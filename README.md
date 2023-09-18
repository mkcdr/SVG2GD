# SVG2GD

Single PHP class to convert SVG to GD images

## Requirement

- PHP version 7.2 or higher.
- SimpleXML library enabled, for reading SVG strings and files.
- GD image library enabled to convert SVG to a raster images.

## Getting Started

SVG2GD has two path modes, continuous and discontinuous when using the 'M' or 'm'
commands due to the limitation of the GD library, so you can use the mode that suit your needs
by changing the `pathMode`.

### Note
> :warning: enabling Anti-aliasing will disable the line thickness.

```php
require_once __DIR__ . '/SVG2GD.php';

$source = '<path to svg file>';
$svg = SVG2GD::fromFile($source);
// $svg->enableAntialiasing(true);
// $svg->pathMode = SVG2GD::PATHMODE_DISCONTINUOUS;
$svg->rasterize();

header('Content-type: image/png');
imagepng($svg->getImage());
```

## Resources

The below resources were used to create this library:
- [Implementation of elliptical arc in path commands](https://www.w3.org/TR/SVG/implnote.html)
- [Quadratic and Cubic Bézier curves](https://en.wikipedia.org/wiki/B%C3%A9zier_curve#Specific_cases)

## Disclaimer
I started this project to learn if it is possible to treat SVG syntax as a drawing commands
and let an image library draw those images thus it's not recommended to use this library in a real project.
