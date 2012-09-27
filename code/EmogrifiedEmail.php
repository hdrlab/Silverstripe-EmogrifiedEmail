<?php
/**
 * Same as the normal system email class, but runs the content through
 * Emogrifier to merge css style inline before sending.
 *
 * NOTE: This is based on:
 * - Mark Guinn's ProcessedEmail class; and
 * - Code from the silverstripe-newsletter-pagesource module.
 *
 * @author Hans de Ruiter
 *
 * @version 1.1 -- 17/01/2012
 *              - Added a check to see if it's a plain-text email, so that 
 *                plain-text content is left unchanged
 * @version 1.0
 */
class EmogrifiedEmail extends Email {

	protected function parseVariables($isPlain = false) {
		parent::parseVariables($isPlain);
		// if it's an html email, filter it through emogrifier
		if (!$isPlain && preg_match("/([\<])([^\>]{1,})*([\>])/i", $this->body)){
			$this->body = $this->emogrify($this->body);
		}
	}
	
	/**
	 * Performs processing on the email content to make CSS styles inline. This
	 * wraps the emogrified library, but extracts external an inline css
	 * defitions.
	 *
	 * @param  string $content
	 * @return string
	 */
	protected function emogrify($content) {
		require_once 'thirdparty/emogrifier/emogrifier.php';

		// order here is seemingly important; 'tidy' seems to strip stuff important for detecting encoding??
		$encoding	= mb_detect_encoding($content, mb_detect_order(), true);
		$content	= $this->tidy($content, $encoding);
		$content	= mb_convert_encoding($content, 'HTML-ENTITIES', $encoding);
		
		$css = array();

		if (!$encoding) {
			$encoding = 'UTF-8';
		}
		
		if(!preg_match("/([\<])([^\>]{1,})*([\>])/i", $this->body))
		{
			// The content is plain text
			return $content;
		}

		$document = new DOMDocument();
		$document->encoding = $encoding;
		$document->strictErrorChecking = false;
		$document->loadHTML($content);
		$document->normalizeDocument();

		$xpath = new DOMXPath($document);

		foreach ($xpath->query("//link[@rel='stylesheet']") as $link) {
			$media = $link->getAttribute('media');
			$cssURL = $link->getAttribute('href');
			$file = $this->findCSSFile($cssURL);
			$baseURL = dirname($cssURL);
			if (file_exists($file)) {
				$contents = trim(file_get_contents($file));
				$contents = EmogrifiedEmail::relToAbsoluteURLs($contents, $baseURL);
				
				if ($contents && (!$media || in_array($media, array('all', 'screen')))) {
					$css[] = $contents;
				}
			}
			
			// Don't need this any more
			$link->parentNode->removeChild($link);
		}

		foreach ($xpath->query('//style') as $style) {
			$type = $style->getAttribute('type');
			$content = trim($style->textContent);

			if ($content && (!$type || $type == 'text/css')) {
				$css[] = $content;
			}
			
			// Don't need this any more
			$style->parentNode->removeChild($style);
		}
		
		
		$emog = new Emogrifier($document->saveHTML());
		$emog->setCSS(implode("\n", $css));
		return $emog->emogrify();
	}
	
	/**
	 * Try and find the css file for a given href
	 *
	 * @param type $href 
	 */
	private function findCSSFile($href) {
		if (strpos($href, '//') !== false) {
			$href = str_replace(Director::absoluteBaseURL(), '', $href);
		}
		if (strpos($href, '?')) {
			$href = substr($href, 0, strpos($href, '?'));
		}
		
		return Director::baseFolder() . '/' . $href;
	}

	/**
	 * Cleans and returns XHTML which is needed for use in DOMDocument
	 *
	 * @param type $content
	 * @param type $encoding
	 * @return string
	 */
	protected function tidy($content, $encoding = 'UTF-8') {
		// Try to use the extension first
		if (extension_loaded('tidy')) {
			$tidy = tidy_parse_string($content, array(
				'clean' => true,
				'output-xhtml' => true,
				'show-body-only' => false,
				'wrap' => 0,
				'input-encoding' => $encoding,
				'output-encoding' => $encoding,
				'anchor-as-name'	=> false,
			));

			$tidy->cleanRepair();
			return $this->rewriteShortcodes('' . $tidy);
		}

		// No PHP extension available, attempt to use CLI tidy.
		$retval = null;
		$output = null;
		@exec('tidy --version', $output, $retval);
		if ($retval === 0) {
			$tidy = '';
			$input = escapeshellarg($content);
			$encoding = str_replace('-', '', $encoding);
                        $encoding = escapeshellarg($encoding);

			// Doesn't work on Windows, sorry, stick to the extension.
			$tidy = @`echo $input | tidy -q --show-body-only no --tidy-mark no --input-encoding $encoding --output-encoding $encoding --wrap 0 --anchor-as-name no --clean yes --output-xhtml yes`;
			return $this->rewriteShortcodes($tidy);
		}

		// Fall back to doing nothing
		return $content;
	}

	protected function rewriteShortcodes($string) {
		return preg_replace('/(\[[^]]*?)(%20)([^]]*?\])/m', '$1 $3', $string);
	}
	
	/** Convert all relative URLs to an absolute URL.
	 * This is almost like HTTP::absoluteURLs() except
	 *
	 * @param $content the content
	 * @param $baseURL the base URL that all URLs are relative to
	 *
	 * @return string the absolute URL
	 */
	static function relToAbsoluteURLs($content, $baseURL) {
		$baseURL = HTTP::absoluteURLs($baseURL);
		$content = str_replace('$CurrentPageURL', $_SERVER['REQUEST_URI'], $content);
		return self::urlRewriter($content, '(substr($URL,0,1) == "/") ? ( "http://" . Director::baseURL() . $URL ) : ( (ereg("^[A-Za-z]+:", $URL)) ? $URL : EmogrifiedEmail::relToAbsoluteURL($URL, \'' . $baseURL . '/' . '\'))' );
	}
	
	/*
	 * Rewrite all the URLs in the given content, evaluating the given string as PHP code
	 *
	 * Put $URL where you want the URL to appear, however, you can't embed $URL in strings
	 * Some example code:
	 *  '"../../" . $URL'
	 *  'myRewriter($URL)'
	 *  '(substr($URL,0,1)=="/") ? "../" . substr($URL,1) : $URL'
	 */
	static function urlRewriter($content, $code) {
		$attribs = array("src","background","a" => "href","link" => "href", "base" => "href");
		foreach($attribs as $tag => $attrib) {
			if(!is_numeric($tag)) $tagPrefix = "$tag ";
			else $tagPrefix = "";

			$regExps[] = "/(<{$tagPrefix}[^>]*$attrib *= *\")([^\"]*)(\")/ie";
			$regExps[] = "/(<{$tagPrefix}[^>]*$attrib *= *')([^']*)(')/ie";
			$regExps[] = "/(<{$tagPrefix}[^>]*$attrib *= *)([^\"' ]*)( )/ie";
		}
		$regExps[] = '/(background-image:[^;]*url *\()([^)]+)(\))/ie';
		$regExps[] = '/(background:[^;]*url *\()([^)]+)(\))/ie';
		$regExps[] = '/(list-style-image:[^;]*url *\()([^)]+)(\))/ie';

		// Make
		$code = 'stripslashes("$1") . (' . str_replace('$URL', 'stripslashes("$2")', $code) . ') . stripslashes("$3")';

		foreach($regExps as $regExp) {
			$content = preg_replace($regExp, $code, $content);
		}

		return $content;
	}
	
	/** Convert a relative URL to an absolute URL.
	 *
	 * @param $link the relative URL
	 * @param $baseURL the base URL that all URLs are relative to
	 *
	 * @return string the absolute URL
	 */
	static function relToAbsoluteURL($link, $baseURL) {
		// If the link is surrounded by parentheses, then we want to strip them 
		// away
		$pre = '';
		$post = '';
		$linkLen = strlen($link);
		if($linkLen > 0)
		{
			if($link[0] == '"' || $link[0] == "'") { 
				$pre = $link[0];
				$link = substr($link, 1);
				$linkLen -= 1;
			}
			if($link[$linkLen - 1] == '"' || $link[0] == "'") { 
				$post = $link[$linkLen - 1]; 
				$linkLen -= 1;
				$link = substr($link, 0, $linkLen);
				
			}	
		}
		
		if($baseURL[strlen($baseURL)-1] != '/')
		{
			$baseURL .= '/';
		}
		
		// Email programs don't like HTTPS, so downgrade to HTTP
		$https = 'https';
		if(stripos($baseURL, $https) === 0)
		{
			$baseURL = 'http' . substr($baseURL, strlen($https));
		}
		
		$absoluteURL = $baseURL . $link;
		$absoluteURL = explode('/', $absoluteURL);
		$keys = array_keys($absoluteURL, '..');
	
		foreach($keys AS $keypos => $key)
		{
			array_splice($absoluteURL, $key - ($keypos * 2 + 1), 2);
		}
	
		$absoluteURL = implode('/', $absoluteURL);
		$absoluteURL = str_replace('./', '', $absoluteURL);
		
		return $pre . $absoluteURL . $post;
	}
}