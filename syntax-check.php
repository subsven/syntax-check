#! /usr/bin/php
<?php

/**
 * syntax-check.php - A small wrapper script to check code syntax
 *
 * This tool is intended to check a code tree - dozens or hundreds of source
 * files - for correct code syntax. The results are reported in the common
 * xUnit format so they can be processed by various existing tools.
 *
 * The syntax checks itself are done by external tools, so this is just a
 * small wrapper script without any deeper functionality.
 *
 * Maybe in the future this will get extented to a more modulare, more 
 * modular structure, but then again, maybe not.
 *
 * Sven Paulus <sven@karlsruhe.org> - 2013-08-13
 *
 * === This is public domain! ===
 */

ini_set('track_errors', true);

$mode_definitions = array(
  'php' => array(
    'suffixes' => array('php', 'inc'),
    'lint' => 'php -l',
    'return_codes_ok' => array(0),
  ),
  'php_noshort' => array(
    'suffixes' => array('php', 'inc'),
    'lint' => 'php -d short_open_tag=0 -l',
    'return_codes_ok' => array(0),
  ),
  'javascript' => array(
    'suffixes' => array('js', 'json'),
    'lint' => '/app1/cmcwork/jsl-0.3.0/jsl -conf /app1/cmcwork/jsl-0.3.0/jsl.conf -process',
    'return_codes_ok' => array(0, 1),
  ),
  'perl' => array(
    'suffixes' => array('pl', 'pm', 'ipl'),
    'lint' => 'perl -cw',
    'return_codes_ok' => array(0),
  )
);

$conf = array(
  'mode' => 'php',
  'excludes' => array(),
  'includes' => array(),
  'suffixes' => array(),
  'lint' => false,
  'return_codes_ok' => array(),
  'outputFile' => false,
  'outputDir' => false,
  'path' => array('.'),
  'quiet' => false,
);

global $recentDir;
$recentDir = '';

function run($path, $conf, &$results)
{
  $objects = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($path),
      RecursiveIteratorIterator::SELF_FIRST);
  foreach($objects as $filename => $object)
  {
    processFile($filename, $conf, &$results);
  }
}

function secure_base64_encode($string)
{
  $encoded = base64_encode($string);

  return strtr($encoded, '/', '_');
}

function preProcess($filename, $suffix)
{

  if ($suffix == 'json')
  {
    # instead of checking the JSON file itself
    # check a temporarily built JavaScript file
    $tempfile = sprintf("/tmp/code-syntax-%s-%s-%s.js",
      getmypid(), time(), secure_base64_encode(md5($filename, true)));
    $contents = file($filename);
    if ($contents === false)
    {
      die("can't open $filename: $php_errormsg\n");
    }
    $contents = "var foo = ".$contents.";\n";
    if (file_put_contents($tempfile, $contents) === false)
    {
      die("can't write $tempfile: $php_errormsg\n");
    }
    return $tempfile;
  }

  return $filename;
}

function postProcess($filename, $suffix)
{

  if ($suffix == 'json') {
    # remove temporarily created javascript file
    unlink($filename) ||
      die("can't remove temporary JSON file $filename: $php_errormsg\n");
  }
}

function processFile($filename, $conf, &$results)
{
  $basename = basename($filename);

  // only check files matching include/exclude rules
  $skip = false;
  foreach ($conf['excludes'] as $p)
  {
    if (preg_match("#$p#", $filename))
    {
      $skip = true;
    }
  }
  foreach ($conf['includes'] as $p)
  {
    if (preg_match("#$p#", $filename))
    {
      $skip = false;
    }
  }
  if ($skip)
  {
    return;
  }

  // only check files with a file suffix (.foo)
  $matches = array();
  if (!preg_match('/\.([^.]+)$/', $basename, $matches))
  {
    return;
  }

  // only check files with known suffixes
  $suffix = $matches[1];
  if (!in_array($suffix, $conf['suffixes']))
  {
    return;
  }

  // only display directory changes
  global $recentDir;
  if ($recentDir != dirname($filename))
  {
    $recentDir = dirname($filename);
    if (!$conf['quiet']) 
    {
      echo "\n\n$recentDir\n";
    }
  }

  // perform test
  $result = array(
    'filename_absolute' => $filename,
    'filename' => $basename,
  );
  $t0 = microtime(true);
  $filename = preProcess($filename, $suffix);

  $fh = popen($conf['lint'].' '.$filename.' 2>&1', 'r');
  if ($fh === false)
  {
    die("ERROR: can't execute $lint $filename: $php_errormsg\n");
  }
  $output = stream_get_contents($fh);
  if ($output === false)
  {
    die("ERROR: can't get output of $lint $filename: $php_errormsg\n");
  }
  $result['info'] = 'File '.$result['filename_absolute'].":\n".$output;
  $status = pclose($fh);

  $result['status'] =in_array($status >> 8, $conf['return_codes_ok']) ? 1 : 0;
  if (!$conf['quiet'])
  {
    print($result['status'] ? '.' : 'E');
  }
  postProcess($filename, $suffix);
  $result['elapsed'] = microtime(true) - $t0;

  $results[] = $result;
}

function generateXML($results, $conf) {

  $dom = new DOMDocument('1.0', 'utf-8');
  $xml_testsuites = $dom->createElement('testsuites');
  $dom->appendChild($xml_testsuites);
  $xml_testsuite = $dom->createElement('testsuite');
  $xml_properties = $dom->createElement('properties');
  $xml_testsuite->appendChild($xml_properties);
  $num_tests = 0;
  $num_failed = 0;
  $elapsed = 0.0;

  foreach ($results as $result)
  {
    $xml_testcase = $dom->createElement('testcase');
    $num_tests++;
    if (!$result['status'])
    {
      $num_failed++;
    }
    $elapsed += $result['elapsed'];
    $xml_testcase->setAttribute('time', $result['elapsed']);
    $name = strtolower($result['filename']);
    $name = preg_replace('|^\.-|', '', strtr($name, '/', '-'));
    $xml_testcase->setAttribute('name', $name);
    $xml_testcase->setAttribute('file', $result['filename_absolute']);
    if (!$result['status']) {
      $xml_failure = $dom->createElement('failure');
      $xml_failure->setAttribute('type', 'PHP_Syntax_Error');
      $xml_failure->addText($result['info']);
      $xml_testcase->appendChild($xml_failure);
    }
    $xml_testsuite->appendChild($xml_testcase);
  }
  $xml_testsuite->setAttribute('name', $conf['mode'].'SyntaxTest');
  $xml_testsuite->setAttribute('errors', 0);
  $xml_testsuite->setAttribute('failures', $num_failed);
  $xml_testsuite->setAttribute('tests', $num_tests);
  $xml_testsuite->setAttribute('time', $elapsed);
  $xml_testsuite->setAttribute('timestamp', strftime("%Y-%m-%dT%H:%M:%S"));
  $xml_testsuites->appendChild($xml_testsuite);

  $dom->formatOutput = true;
  return $dom->saveXML();
}

function generateOutputDir($results, $conf)
{

  if (!is_dir($conf['outputDir']))
  {
    mkdir($conf['outputDir'], 0775, true) ||
      die("can't create output directory ".$conf['outputDir'].
          ": $php_errormsg\n");
  }
  chdir($conf['outputDir']) ||
    die("can't change to output directory ".$conf['outputDir'].
        ": $php_errormsg\n");

  # one output file per file checked
  $html = '';
  foreach ($results as $result)
  {
    $filename = $result['filename_absolute'].'.txt';
    $filename = preg_replace('|\./|', '', $filename);
    $filename = preg_replace('|/|', '_DIR_', $filename);
    file_put_contents($filename, $result['info']) ||
      die("can't create $filename: $php_errormsg\n");
    $html .= sprintf("<li><a href=\"%s\" style=\"color:%s\">%s</a></li>\n",
      $filename,
      ($result['status'] ? "green" : "red"),
      $result['filename_absolute']);
  }

  # one global index file
  $html = '<html><head><title>'.$conf['mode'].' syntax test</title></head>'.
          '<body><h1>'.$conf['mode'].' syntax test</h1><ul>'.
          $html.
          '</ul></body></html>'."\n";
  file_put_contents('index.html', $html) ||
    die("can't create index.html: $php_errormsg\n");
}

function helpAndExit()
{
  $str = <<< 'EOF'
  usage: code-syntax.pl [options] BASEDIR
  valid options are:
  --mode MODE         operation mode 
                      (php, php_noshort, javascript, perl; default: php)
  --exclude PATTERN   exclude subdirectories/files matching PATTERN  [*]
  --include PATTERN   include subdirectories/files matching PATTERN  [*]
  --suffix STRING     include suffix in evaluation (default: php, inc)  [*]
  --lint FILENAME     define lint command line to use (default: php -l)
  --output FILENAME   file to write JUnit XML output to (default: stdout)
  --outputdir DIR     directory to write per file output to (default: none)
  --path DIR          directory to start scan from (default: .) [*]
  --quiet             suppress any output
  --help              display this help output

  options with [*] may be given multiple times
EOF;
  errorAndExit(preg_replace('/^  /', '', $str));
}

function errorAndExit($message)
{
  fprintf(STDERR, "%s\n", $message);
  exit(1);
}

function copyArrayValue($value)
{
  $output = array();
  if (is_array($value))
  {
    foreach ($value as $v)
    {
      if (!preg_match('/^\s*$/', $v))
      {
        $output[] = trim($v);
      }
    }
  }
  else
  {
    if (!preg_match('/^\s*$/', $value))
    {
      $output[] = $value;
    }
  }
  
  return $output;
}

/**************************************************************************
 *
 * main
 *
 */

$parameters = array(
  'm:' => 'mode:',
  'e:' => 'exclude:',
  'i:' => 'include:',
  's:' => 'suffix:',
  'k:' => 'lint:',
  'o:' => 'output:',
  'd:' => 'outputdir:',
  'p:' => 'path:',
  'q' => 'quiet',
  'h' => 'help',
);

$options = getopt(implode('', array_keys($parameters)), $parameters);

if ($options === false)
{
  helpAndExit();
}

foreach ($options as $option => $value)
{
  if (!is_array($value))
  {
    $value = trim($value);
  }
  switch ($option) {
    case 'm':
    case 'mode':
      if (!array_key_exists($value, $mode_definitions))
      {
        errorAndExit("unknown mode '$value'");
      }
      $conf['mode'] = $value;
      break;
    case 'e':
    case 'exclude':
      $conf['excludes'] = copyArrayValue($value);
      break;
    case 'i':
    case 'include':
      $conf['includes'] = copyArrayValue($value);
      break;
    case 's':
    case 'suffix':
      $conf['suffixes'] = copyArrayValue($value);
      break;
    case 'l':
    case 'lint':
      $conf['lint'] = $value;
      break;
    case 'o':
    case 'output':
      $conf['outputFile'] = $value;
      break;
    case 'd':
    case 'outputdir':
      $conf['outputDir'] = $value;
      break;
    case 'p':
    case 'path':
      $conf['path'] = copyArrayValue($value);
      break;
    case 'q':
    case 'quiet':
      $conf['quiet'] = true;
      break;
    case 'h':
    case 'help':
      helpAndExit();
      break;
  }
}

// set some values unless not yet defined from the command line
if (!$conf['lint'])
{
  $conf['lint'] = $mode_definitions[$conf['mode']]['lint'];
}
if (!$conf['suffixes'])
{
  $conf['suffixes'] = $mode_definitions[$conf['mode']]['suffixes'];
}
$conf['return_codes_ok'] = $mode_definitions[$conf['mode']]['return_codes_ok'];


# iterate over the file system
$results = array();
foreach ($conf['path'] as $path)
{
  if (!is_dir($path))
  {
    errorAndExit("path '$path' not found!");
  }   
  run($path, $conf, $results);
}

if (!$conf['quiet'])
{
  printf("\n\n%d files checked\n", count($results));
}

if ($conf['outputFile']) {
  if (file_put_contents($conf['outputFile'],
          generateXML($results, $conf)) === false)
  {
    die("can't write outputFile ".$conf['outputFile'].": $php_errormsg\n");
  }
} else {
  echo generateXML($results, $conf);
}
if ($conf['outputDir'])
{
  generateOutputDir($results, $conf);
}
