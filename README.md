# QR

Draw a QR code as an inline SVG on your own server, so the text in it never
goes to a third party.

## What it does

The text in a QR code is often a secret: a link that pairs a phone with the
site and carries an application password, a sign-in token, a two-factor
enrolment URI. Every QR service takes that text in a URL, which posts the
credential to a stranger and into their logs, and a script from a CDN is the
same stranger one step removed.

This encodes the code in PHP (ISO/IEC 18004, byte mode, versions 1 to 40,
all four error-correction levels) and hands back a self-contained SVG: no
image requests, no JavaScript, nothing that refers outside itself.

## Features

* Draw a code as an SVG to print straight into a page or an admin screen
* Get it as a `data:` URI for an `<img>` tag or a CSS background
* Pick the error-correction level, or let it choose the smallest readable code
* Set the module size, the margin, the colours and the screen-reader label
* Encode any string as its bytes, UTF-8 included
* Ask how much text a level holds before you build the link
* Get the raw grid of modules to draw it some other way

It does not decode codes, and it does not do the numeric, alphanumeric or
kanji modes: a phone reads byte mode for everything, and the code is a size
or two larger at most.

## Installation

```bash
composer require sugarcommerce/qr
```

## Quick start

```php
use SugarCommerce\Qr\QR;

$link = add_query_arg( 'token', $token, 'myapp://connect' );

echo QR::svg( $link, [
	'module' => 4,
	'label'  => __( 'Scan with the app to connect', 'my-plugin' ),
] );
```

The SVG is markup the library built from modules; the only text in it, the
label and the colours, is escaped there.

## Error correction

With no level, a code is made at M (about 15% of it can be lost and it still
reads), falling back to L only when the text is too long for M at the
largest size. That suits a screen, where nothing smudges and a smaller code
is easier to scan.

Ask for a level and you get that level and no other: an exception rather
than a quiet fallback when the text does not fit.

```php
// A code for a printed ticket, or one with a logo laid over its middle.
$svg = QR::svg( $ticket_url, [ 'level' => 'H' ] );
```

| Level | Survives losing | Most bytes (version 40) |
|-------|-----------------|-------------------------|
| L     | about 7%        | 2,953                   |
| M     | about 15%       | 2,331                   |
| Q     | about 25%       | 1,663                   |
| H     | about 30%       | 1,273                   |

`QR::capacity( 'Q', 10 )` says how many bytes a level holds at a size (151
here); with no version it is the largest.

## Drawing options

```php
QR::svg( $text, [
	'module' => 6,      // pixels per module (the width and height attributes)
	'quiet'  => 4,      // modules of margin on each side; the standard says 4
	'level'  => null,   // L, M, Q or H; null for M, then L
	'label'  => null,   // aria-label; null for "QR code"
	'dark'   => '#000', // the modules
	'light'  => '#fff', // the ground; '' leaves it transparent
] );
```

A colour is #hex, a name, a colour function such as `rgb()` or `oklch()`, or
`var(--name)`; anything else throws `InvalidArgumentException`.

The SVG has a `viewBox`, so CSS can scale it to any size without blurring;
`shape-rendering="crispEdges"` keeps the modules sharp. Keep good contrast and
the dark modules darker than the ground: many readers do not handle a
negative code.

## As an image address

```php
printf( '<img src="%s" alt="" width="180" height="180">', esc_attr( QR::data_uri( $url ) ) );
```

The same picture, base64-encoded. Some email clients refuse `data:` images,
so test where it is going.

## The grid itself

```php
$modules = QR::matrix( $text, 'Q' ); // rows of booleans, true for dark, no margin
QR::version( $modules );             // 1 to 40
QR::level( $modules );               // 'Q', read back from the code's format bits
```

`matrix()` throws `InvalidArgumentException` when the level is not one of the
four or the text is longer than the level holds; so do `svg()` and
`data_uri()`.

## How it is checked

The suite pins codes module for module to Project Nayuki's reference encoder
at every level and from the smallest size to the largest, checks the
capacity of every version at every level against the standard's table, and
checks the finders, timing lines, format and version bits against the
standard's rules.

## Security

* Keeps the text off the network: the code is made in PHP, with no service, CDN script or image request
* Escapes the label and the colours inside their attributes, so neither can break out into markup
* Refuses a colour that is not one (`url(...)`, markup, anything but #hex, a name, a colour function or `var(--name)`), so the picture never fetches an address
* Refuses text longer than a code holds before encoding any of it, so a huge string costs nothing

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
