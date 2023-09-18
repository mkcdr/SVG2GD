<?php
/**
 * SVG2GD
 */
class SVG2GD
{
    const PATHMODE_CONTINUOUS = 0;
    const PATHMODE_DISCONTINUOUS = 1;
    const CSSRegex = '/([\w\s\.,\-#]+)?{\s*(.*?)\s*}/';

    // array of all path commands and the number of
    // parameters are needed.
    private $commands = [
        'm' => 2,
        'l' => 2,
        'h' => 1,
        'v' => 1,
        'c' => 6,
        's' => 4,
        'q' => 4,
        't' => 2,
        'a' => 7,
        'z' => 0,
    ];

    private $image;                     // GD image result
    private $antialias = false;         // switch on/off anti aliasing
    private $svg;                       // SVG elements
    private $styles = [];               // CSS styles
    private $currentAttributes = [];    // current attribute or styles in SVG tree
    public $pathMode = self::PATHMODE_CONTINUOUS; // Keep points continuous 

    /**
     * Protected constructor
     */
    protected function __construct() {}

    /**
     * Return object from filename
     * 
     * @param string $filename
     * @return self
     */
    public static function fromFile(string $filename) : self
    {
        $svg = simplexml_load_file($filename, NULL, LIBXML_COMPACT);
        $obj = new self();
        $obj->setSVG($svg);

        return $obj;
    }

    /**
     * Return object from string
     * 
     * @param string $svg
     * @return self
     */
    public static function fromString(string $svg) : self
    {
        $svg = simplexml_load_string($svg, NULL, LIBXML_COMPACT);
        $obj = new self();
        $obj->setSVG($svg);

        return $obj;
    }

    /**
     * Set SVG SimpleXMLElement content
     * 
     * @param SimpleXMLElement $svg
     * @return void
     */
    public function setSVG(SimpleXMLElement $svg) : void
    {
        $this->svg = $svg;
    }

    /**
     * Get GD image
     * 
     * @return GdImage|null
     */
    public function getImage()
    {
        return $this->image;
    }

    /**
     * Switch on/off anti aliasing
     * Warning: Enabling anti-aliasing will disable line thickness
     * 
     * @param bool $mode
     * @return void
     */
    public function enableAntialiasing(bool $mode) : void
    {
        $this->antialias = $mode;
    }

    /**
     * Reformat path string by creating a space between each command and parameter
     * 
     * @param string $d
     * @return string
     */
    private function reformatPathString(string $d) : string
    {
        $d = str_replace(',', ' ', $d);
        $d = preg_replace('/(\d)-/', "\${1} -", $d);
        $d = preg_replace('/([a-z])/i', " \${1} ", $d);
        $d = preg_replace_callback(
            '/(\.\d+)+/', 
            function ($matches) {
                $m = $matches[0];
                $m = str_replace('.', ' .', $m);
                $m = trim($m);
                return $m;
            }, $d);

        $d = preg_replace('/\s+/', ' ', $d);
        $d = trim($d);

        return $d;
    }

    /**
     * Assign the current attributes to be applied in a XML node
     * 
     * @param SimpleXMLElement $node
     * @return void
     */
    private function assignAttributes(SimpleXMLElement $node) : void
    {
        foreach ($node->attributes() as $k => $v) 
        {
            if (trim($v->__toString()) !== '')
            {
                $this->currentAttributes[strtolower($k)] = $v->__toString();
            }
        }   
    }

    /**
     * Convert SVG commands to a GD image
     * 
     * @return void
     */
    public function rasterize() : void
    {
        $this->assignAttributes($this->svg);
        
        if (!isset($this->currentAttributes['viewbox']))
        {
            throw new Exception('SVG ViewBox attribute not defined');
        }
                
        // create a new image
        list(,, $width, $height) = explode(' ', $this->currentAttributes['viewbox']);
        
        $this->image = imagecreatetruecolor($width, $height);
        imagealphablending($this->image, true);
        imagesavealpha($this->image, true);
        imagefill($this->image, 0, 0, 0x7f000000);  // add a transparent background
        
        if ($this->antialias)
        {
            imageantialias($this->image, true);     // enable anti-alias - stroke thickness doesn't work when anti-alias is enabled -
        }

        $this->proccessXMLElementRec($this->svg);
    }

    /**
     * Process the SVG tree recursivly
     * 
     * @param SimpleXMLElement $parent
     * @return void
     */
    private function proccessXMLElementRec(SimpleXMLElement $parent, int $level=0) : void
    {
        foreach ($parent->children() as $node)
        {
            $prevAttributes = $this->currentAttributes;
            $this->assignAttributes($node);
            $this->applyStyles();

            if ($node->count()) 
            {
                $this->proccessXMLElementRec($node, $level+1);
            }

            $fill = $this->currentAttributes['fill'];
            $stroke = $this->currentAttributes['stroke'];
            $thickness = $this->currentAttributes['stroke-width'];

            imagesetthickness($this->image, $thickness);

            switch (strtolower($node->getName())) 
            {
                case 'line':
                    $x1 = $this->currentAttributes['x1'];
                    $y1 = $this->currentAttributes['y1'];
                    $x2 = $this->currentAttributes['x2'];
                    $y2 = $this->currentAttributes['y2'];

                    if ($thickness)
                    {
                        imageline($this->image, $x1, $y1, $x2, $y2, $stroke);
                    }
                    break;

                case 'circle':
                    $cx = $this->currentAttributes['cx'];
                    $cy = $this->currentAttributes['cy'];
                    $r = $this->currentAttributes['r'];
                    
                    imagefilledellipse($this->image, $cx, $cy, 2*$r, 2*$r, $fill);

                    if ($thickness)
                    {
                        $thicknessOffset = $thickness / 2;
                        imagefilledellipse($this->image, $cx, $cy, 2*$r+$thicknessOffset, 2*$r+$thicknessOffset, $stroke);
                        imagealphablending($this->image, false);
                        imagefilledellipse($this->image, $cx, $cy, 2*$r-$thicknessOffset, 2*$r-$thicknessOffset, $fill);
                        imagealphablending($this->image, true);
                    }
                    break;

                case 'ellipse':
                    $cx = $this->currentAttributes['cx'];
                    $cy = $this->currentAttributes['cy'];
                    $rx = $this->currentAttributes['rx'];
                    $ry = $this->currentAttributes['ry'];

                    imagefilledellipse($this->image, $cx, $cy, 2*$rx, 2*$ry, $fill);

                    if ($thickness)
                    {
                        $thicknessOffset = $thickness / 2;
                        imagefilledellipse($this->image, $cx, $cy, 2*$rx+$thicknessOffset, 2*$ry+$thicknessOffset, $stroke);
                        imagealphablending($this->image, false);
                        imagefilledellipse($this->image, $cx, $cy, 2*$rx-$thicknessOffset, 2*$ry-$thicknessOffset, $fill);
                        imagealphablending($this->image, true);
                    }
                    break;

                case 'rect':
                    $x = $this->currentAttributes['x'];
                    $y = $this->currentAttributes['y'];
                    $width = $this->currentAttributes['width'];
                    $height = $this->currentAttributes['height'];

                    imagefilledrectangle($this->image, $x, $y, $x+$width, $y+$height, $fill);

                    if ($thickness)
                    {
                        imagerectangle($this->image, $x, $y, $x+$width, $y+$height, $stroke);
                    }
                    break;

                case 'polygon':
                    $points = $this->currentAttributes['points'];
                    $points = $this->reformatPathString($points);
                    $points = explode(' ', $points);
                    $pointsCount = count($points) / 2;
                    
                    if ($pointsCount > 2)
                    {
                        imagefilledpolygon($this->image, $points, $pointsCount, $fill);
    
                        if ($thickness)
                        {
                            imagepolygon($this->image, $points, $pointsCount, $stroke);
                        }
                    }
                    else
                    {
                        if ($thickness)
                        {
                            $this->drawLines($points, $pointsCount, $stroke);
                        }
                    }
                    break;

                case 'polyline':
                    $points = $this->currentAttributes['points'];
                    $points = $this->reformatPathString($points);
                    $points = explode(' ', $points);
                    $pointsCount = count($points) / 2;

                    if ($pointsCount > 2)
                    {
                        imagefilledpolygon($this->image, $points, $pointsCount, $fill);
                    }

                    if ($thickness)
                    {
                        $this->drawLines($points, $pointsCount, $stroke);
                    }
                    break;

                case 'path':
                    $path = $this->currentAttributes['d'];
                    $path = $this->reformatPathString($path);
                    
                    $this->drawPath($path, $fill, $stroke, $thickness);
                    break;

                case 'text':
                    $x = $this->currentAttributes['x'];
                    $y = $this->currentAttributes['y'];
                    $text = $node->__toString();
                    imagestring($this->image, 16, $x, $y - 16, $text, $fill);
                    break;
                
                case 'style':
                    $css = $node->__toString();
                    
                    if (preg_match_all(self::CSSRegex, $css, $matches))
                    {
                        array_shift($matches);
                        $count = count($matches[0]);
                        for ($j=0; $j < $count; $j++) { 
                            $cssidentifiers = trim($matches[0][$j]);
                            $styles = trim($matches[1][$j], ' ');
                            $styles = explode(';', trim($styles, ';'));

                            foreach ($styles as $style)
                            {
                                $style = array_map('trim', explode(':', $style));
                                if (!empty($style[1]))
                                {
                                    $this->styles[$cssidentifiers][$style[0]] = $style[1];
                                }
                            }
                        }
                    }
                    break;
                
                default:
                    # code...
                    break;
            }

            $this->currentAttributes = $prevAttributes;
        }
    }

    /**
     * get styles from css string
     * 
     * @param string $css
     * @return array
     */
    public function getStyles(string $css) : array
    {
        $styles = [];

        $css = trim($css);
        $css = trim($css, ';');
        $css = explode(';', $css);

        foreach ($css as $c)
        {
            $c = array_map('trim', explode(':', $c));
            if ($c[1] !== '')
            {
                $styles[$c[0]] = $c[1];
            }
        }

        return $styles;
    }

    /**
     * get color as int
     * 
     * @param mixed $color
     * @return int
     */
    public function getColor($color) : int
    {
        if (is_int($color))
        {
            return $color;
        }

        if ($color == 'none' || $color == 'transparent')
        {
            return imagecolorallocatealpha($this->image, 0, 0, 0, 127);
        }

        if (preg_match('/#[a-f0-9]{3,8}/i', $color))
        {
            $color = trim($color, '#');
            $count = strlen($color);

            if ($count < 5)
            {
                $repeat = 2;
                $split = 1;
            }
            else
            {
                $repeat = 1;
                $split = 2;
            }

            $color = array_map(function ($i) use ($repeat) {
                return hexdec(str_repeat($i, $repeat));
            }, str_split($color, $split));

            if ($count % 4 == 0)
            {
                $color = imagecolorallocatealpha($this->image, $color[0], $color[1], $color[2], (0xff - $color[3]) / 2);
            }
            else
            {
                $color = imagecolorallocate($this->image, $color[0], $color[1], $color[2]);
            }
        }
        
        return (int) $color;
    }

    /**
     * Apply styles
     * 
     * @return void 
     */
    private function applyStyles() : void
    {
        $fill = isset($this->currentAttributes['fill']) ? $this->currentAttributes['fill'] : 0x000000;
        $stroke = isset($this->currentAttributes['stroke']) ? $this->currentAttributes['stroke'] : 0x7f000000;
        $thickness = isset($this->currentAttributes['stroke-width']) ? $this->currentAttributes['stroke-width'] : 0;
        
        // get css styles
        $styles = [];
        $cssidentifiers = [];

        // get styles by class name
        if (isset($this->currentAttributes['class']))
        {
            $cssidentifiers[] = '.' . $this->currentAttributes['class'];
        }

        // get styles by id
        if (isset($this->currentAttributes['id']))
        {
            $cssidentifiers[] = '#' . $this->currentAttributes['id'];
        }

        foreach ($cssidentifiers as $cssidentifier)
        {
            if (isset($this->styles[$cssidentifier]))
            {
                $styles = array_merge($styles, $this->styles[$cssidentifier]);
            }
        }

        // get inline css
        if (isset($this->currentAttributes['style']))
        {
            $styles = array_merge($styles, $this->getStyles($this->currentAttributes['style']));
        }

        if (isset($styles['fill']))
        {
            $fill = $styles['fill'];
        }

        if (isset($styles['stroke']))
        {
            $stroke = $styles['stroke'];
        }

        if ($stroke !== 0x7f000000)
        {
            $thickness = 1;
        }

        if (isset($styles['stroke-width']))
        {
            $thickness = $styles['stroke-width'];
        }

        $this->currentAttributes['fill'] = $this->getColor($fill);
        $this->currentAttributes['stroke'] = $this->getColor($stroke);
        $this->currentAttributes['stroke-width'] = (int) $thickness;
    }

    /**
     * Calculate the angle between two 2D vectors
     * 
     * @param float $ux
     * @param float $uy
     * @param float $vx
     * @param float $vy
     * @return float
     */
    private function angleBetween2DVectors(float $ux, float $uy, float $vx, float $vy) : float
    {
        $sign = ($ux * $vy - $uy * $vx) < 0 ? -1 : 1;
        $dotproduct = $ux * $vx + $uy * $vy;
        $u_normal = sqrt($ux * $ux + $uy * $uy); 
        $v_normal = sqrt($vx * $vx + $vy * $vy);
        $arg = $dotproduct / ($u_normal * $v_normal);

        return $sign * acos($arg) / M_PI * 180;
    }

    /**
     * Calculate the Quadratic Bezier curves coordinate at a point
     * 
     * @param float a0
     * @param float a1
     * @param float a2
     * @param float t
     * @return float
     */
    private function quadraticBezierCurve(float $a0, float $a1, float $a2, float $t) : float
    {
        return pow(1-$t, 2) * $a0 + 2 * (1-$t) * $t * $a1 + pow($t, 2) * $a2;
    }

    /**
     * Calculate the Cubic Bezier curves coordinate at a point
     * 
     * @param float $a0
     * @param float $a1
     * @param float $a2
     * @param float $a3
     * @param float t
     * @return float
     */
    private function cubicBezierCurve(float $a0, float $a1, float $a2, float $a3, float $t) : float
    {
        return pow(1-$t, 3) * $a0 + 3 * pow(1-$t, 2) * $t * $a1 + 3 * (1-$t) * pow($t, 2) * $a2 + pow($t, 3) * $a3;
    }

    /**
     * Draw lines on image using list of points
     * 
     * @param array $points
     * @param array $pointsCount
     * @param int $stroke
     * @return void
     */
    private function drawLines(array $points, int $pointsCount, int $stroke) : void
    {
        if ($pointsCount > 1)
        {
            $x1 = $points[0];
            $y1 = $points[1];

            $i = 1;

            while ($i < $pointsCount)
            {
                $x2 = $points[2*$i];
                $y2 = $points[2*$i+1];
                imageline($this->image, $x1, $y1, $x2, $y2, $stroke);
                $x1 = $x2;
                $y1 = $y2;
                $i++;
            }
        }
    }

    /**
     * Draw a shape using a path string or array
     * @since 1.0
     * 
     * @param string|array $path
     * @return void
     */
    private function drawPath($path, int $fill, int $stroke, int $thickness) : void
    {   
        $points = [];       // array of 2D points x and y coordinates comes consecutively
        $params = [];       // array of numeric parameters for path commands
        $paramsCount = 0;   // track the number of parameters
        $pointsCount = 0;   // track the number of points 
        $startOffset = 0;   // track the starting point to connect polygon using the 'z' command
        $currCmd = '';      // current command
        $prevCmd = '';      // previous command
        $dx = 0;            // delta value for x coordinate if lowercase path command is used
        $dy = 0;            // delta value for y coordinate if lowercase path command is used
        $lx = 0;            // last x coordinate
        $ly = 0;            // last y coordinate
        $x1 = 0;            // x coordinate for starting control point of a curves
        $y1 = 0;            // y coordinate for starting control point of a curves
        $x2 = 0;            // x coordinate for ending control point of a curves
        $y2 = 0;            // y coordinate for ending control point of a curves
        
        if (is_string($path))
        {
            $path = $this->reformatPathString($path);
            $path = explode(' ', $path);
        }

        // TODO: break out at the first error occurrence (params array not empty after the command ended)
        //       and points that have been collected so far are the result.
        foreach ($path as $cmd) {

            // push numeric parameter for the current command
            if (is_numeric($cmd)) {
                $params[] = floatval($cmd);
                $paramsCount++;
            }

            // if the cmd is not numeric then we assume
            // it's a letter for a new command
            else 
            {
                // if $params is not empty then the path string has a wrong 
                // format and the foreach loop should stop here. 
                if ($params) break;
                $currCmd = $cmd;
            }
            
            $sCurrCmd = strtolower($currCmd);

            // check if the number of parameters is sufficient for the current command
            if (!isset($this->commands[$sCurrCmd]) || $paramsCount != $this->commands[$sCurrCmd])
                continue;
                
            
            // get the last point coordinate
            if ($points)
            {
                $lx = $points[2 * $pointsCount - 2];
                $ly = $points[2 * $pointsCount - 1];
            }

            // set the delta values for x and y coordinates, 
            // lowercase commands start from the last point
            // ord('a') = 97, ord('z') = 122;
            $currCmdOrd = ord($currCmd);

            if ($currCmdOrd >= 97 && $currCmdOrd <= 122)
            {
                $dx = $lx;
                $dy = $ly;
            }
            else
            {
                $dx = 0;
                $dy = 0;
            }

            // handle path command
            switch ($sCurrCmd) {
                case 'm':
                    if ($this->pathMode && $points)
                    {
                        // draw polygon and reset $points
                        if ($pointsCount > 2)
                        {
                            imagefilledpolygon($this->image, $points, $pointsCount, $fill);
                        }

                        if ($thickness)
                        {
                            $this->drawLines($points, $pointsCount, $stroke);
                        }

                        $points = [];
                        $pointsCount = 0;
                    }

                    if (!in_array($prevCmd, ['M', 'm']))
                    {
                        $startOffset = 2 * $pointsCount;
                    }
                
                case 'l':
                    $points[] = $params[0] + $dx;
                    $points[] = $params[1] + $dy;
                    $pointsCount++;
                    break;

                case 'h':
                    $points[] = $params[0] + $dx;
                    $points[] = $ly;
                    $pointsCount++;
                    break;
                
                case 'v':
                    $points[] = $lx;
                    $points[] = $params[0] + $dy;
                    $pointsCount++;
                    break;

                case 'c':
                    $x1 = $params[0] + $dx;
                    $y1 = $params[1] + $dy;
                    $x2 = $params[2] + $dx;
                    $y2 = $params[3] + $dy;

                    for ($t=0; $t <= 1.02; $t+=0.02) 
                    {
                        // Cubic Bezier curves
                        $points[] = $this->cubicBezierCurve($lx, $x1, $x2, $params[4] + $dx, $t);
                        $points[] = $this->cubicBezierCurve($ly, $y1, $y2, $params[5] + $dy, $t);
                        $pointsCount++;
                    }
                    break;

                case 's':
                    $x1 = $lx;
                    $y1 = $ly;

                    if (in_array($prevCmd, ['C', 'c', 'S', 's']))
                    {
                        $x1 = 2 * $lx - $x2;
                        $y1 = 2 * $ly - $y2;
                    }

                    $x2 = $params[0] + $dx;
                    $y2 = $params[1] + $dy;

                    for ($t=0; $t <= 1.02; $t+=0.02) 
                    {
                        // Cubic Bezier curves
                        $points[] = $this->cubicBezierCurve($lx, $x1, $x2, $params[2] + $dx, $t);
                        $points[] = $this->cubicBezierCurve($ly, $y1, $y2, $params[3] + $dy, $t);
                        $pointsCount++;
                    }
                    break;

                case 'q':
                    $x1 = $params[0] + $dx;
                    $y1 = $params[1] + $dy;

                    for ($t=0; $t <= 1.02; $t+=0.02) 
                    {
                        // Quadratic Bezier curves
                        $points[] = $this->quadraticBezierCurve($lx, $x1, $params[2] + $dx, $t);
                        $points[] = $this->quadraticBezierCurve($ly, $y1, $params[3] + $dy, $t);
                        $pointsCount++;
                    }
                    break;
                
                case 't':

                    if (in_array($prevCmd, ['Q', 'q', 'T', 't']))
                    {
                        $x1 = 2 * $lx - $x1;
                        $y1 = 2 * $ly - $y1;
                    }
                    else
                    {
                        $x1 = $lx;
                        $y1 = $ly;
                    }

                    for ($t=0; $t <= 1.02; $t+=0.02) 
                    {
                        // Cubic Bezier curves
                        $points[] = $this->quadraticBezierCurve($lx, $x1, $params[0] + $dx, $t);
                        $points[] = $this->quadraticBezierCurve($ly, $y1, $params[1] + $dy, $t);
                        $pointsCount++;
                    }
                    break;

                case 'a':
                    $x1 = $lx;
                    $y1 = $ly;
                    $rx = abs($params[0]);
                    $ry = abs($params[1]);
                    $angle = $params[2];
                    $large_arc_flag = $params[3]; 
                    $sweep_flag = $params[4]; 
                    $x2 = $params[5] + $dx;
                    $y2 = $params[6] + $dy;

                    if ($rx == 0 || $ry == 0)
                    {
                        $points[] = $x1;
                        $points[] = $y1;
                        $points[] = $x2;
                        $points[] = $y2;
                        $pointsCount += 2;
                    }
                    else
                    {
                        // step 1
                        $cosangle = cos($angle * M_PI / 180);
                        $sinangle = sin($angle * M_PI / 180);

                        $x1_ = ($cosangle * ($x1 - $x2) + $sinangle * ($y1 - $y2)) / 2;
                        $y1_ = ($cosangle * ($y1 - $y2) - $sinangle * ($x1 - $x2)) / 2;

                        // ensure radii are large enough
                        $l = ($x1_*$x1_)/($rx*$rx) + ($y1_*$y1_)/($ry*$ry);

                        if ($l > 1)
                        {
                            $rx = sqrt($l) * $rx;
                            $ry = sqrt($l) * $ry;
                        }

                        // step 2
                        $cc = ($rx*$rx * $ry*$ry - $rx*$rx * $y1_*$y1_ - $ry*$ry * $x1_*$x1_) / ($rx*$rx * $y1_*$y1_ + $ry*$ry * $x1_*$x1_);
                        $cc = abs($cc);
                        $cc = sqrt($cc);
                        $cc *= ($large_arc_flag == $sweep_flag) ? -1 : 1;
                        $cx_ = $cc * (($rx * $y1_) / $ry);
                        $cy_ = $cc * (($ry * $x1_) / $rx) * (-1);

                        // step 3: arc center
                        $cx = $cosangle * $cx_ - $sinangle * $cy_ + ($x1 + $x2) / 2;
                        $cy = $sinangle * $cx_ + $cosangle * $cy_ + ($y1 + $y2) / 2;


                        // step 4: angles
                        $theta1 = $this->angleBetween2DVectors(1, 0, ($x1_ - $cx_) / $rx, ($y1_ - $cy_) / $ry);
                        $deltaTheta = $this->angleBetween2DVectors(($x1_ - $cx_) / $rx, ($y1_ - $cy_) / $ry, (- $x1_ - $cx_) / $rx, (- $y1_ - $cy_) / $ry) % 360;
                        
                        if ($sweep_flag == 0 && $deltaTheta > 0)
                        {
                            $deltaTheta -= 360;
                        }
                        elseif ($sweep_flag == 1 && $deltaTheta < 0)
                        {
                            $deltaTheta += 360;
                        }
                        elseif ($sweep_flag == 0 && $deltaTheta == 0)
                        {
                            $deltaTheta -= 180;
                        }
                        elseif ($sweep_flag == 1 && $deltaTheta == 0)
                        {
                            $deltaTheta += 180;
                        }

                        $theta2 = $theta1 + $deltaTheta;

                        // arc points
                        if ($theta2 < $theta1)
                        {
                            for ($k=$theta1; $k >= $theta2; $k-=1) 
                            {
                                $cosk = cos($k * M_PI / 180);
                                $sink = sin($k * M_PI / 180);
                                $px = $cosangle * $rx * $cosk - $sinangle * $ry * $sink + $cx;
                                $py = $sinangle * $rx * $cosk + $cosangle * $ry * $sink + $cy;
                                $points[] = $px;
                                $points[] = $py;
                                $pointsCount++;
                            }
                        }
                        else
                        {
                            for ($k=$theta1; $k <= $theta2; $k+=1) 
                            {
                                $cosk = cos($k * M_PI / 180);
                                $sink = sin($k * M_PI / 180);
                                $px = $cosangle * $rx * $cosk - $sinangle * $ry * $sink + $cx;
                                $py = $sinangle * $rx * $cosk + $cosangle * $ry * $sink + $cy;
                                $points[] = $px;
                                $points[] = $py;
                                $pointsCount++;
                            }
                        }
                    }

                    break;

                case 'z':
                    $points[] = $points[$startOffset];
                    $points[] = $points[$startOffset + 1];
                    $pointsCount++;
                    break;

                default:
                    # code...
                    break;
            }

            $params = [];
            $paramsCount = 0;
            $prevCmd = $currCmd;
            
        }

        // finally draw the shape
        if ($pointsCount > 2)
        {
            imagefilledpolygon($this->image, $points, $pointsCount, $fill);
        }

        if ($thickness)
        {
            $this->drawLines($points, $pointsCount, $stroke);
        }
    }

    /**
     * Destroy GD image
     * 
     * @return void 
     */
    public function __destruct()
    {
        if ($this->image)
        {
            imagedestroy($this->image);
        }
    }
}
