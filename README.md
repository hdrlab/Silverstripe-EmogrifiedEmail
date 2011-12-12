# EmogrifiedEmail Module

## Maintainer Contact

 * Hans de Ruiter <hans (at) hdrlab (dot) co (dot) nz>

## Requirements

 * SilverStripe 2.3 or newer
 * Database: MySQL 5+, SQLite3, Postgres 8.3, SQL Server 2008

## Download/Information

* http://hdrlab.org.nz/projects/silverstripe-php-projects/EmogrifiedEmail/

## Introduction

Extends the Email class to inline CSS code so that the result is compatible with
most email programs.


## Installation

Copy this module into your Silverstripe installation following the standard 
module installation procedure. Next, modify all code that needs to send 
emogrified emails to use EmogrifiedEmail instead of the Email class. For 
example, newsletters can be made emogrified by default by updating 
NewsletterEmail.php so that NewsletterEmail extends EmogrifiedEmail instead of
the Email class.
