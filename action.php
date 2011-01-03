<?php

/**
 * Select Template Pages for your Content
 * The templates Pages have to have the entry @@CONTENT@@
 * the template per page can be defined using the META plugin
 * 
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author   Sebastian Herbord   <sherb@gmx.net>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC'))
    die();

if (!defined('DOKU_LF'))
    define('DOKU_LF', "\n");
if (!defined('DOKU_TAB'))
    define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN'))
    define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');

require_once (DOKU_PLUGIN . 'action.php');
require_once(DOKU_INC . 'inc/pageutils.php');


function apply_whitelist($functioncall) {
  GLOBAL $repeat;
  // a list of functions to allow in calc-expressions. this is not the whole set of php math functions,
  // mostly because I was to lazy to verify they all make sense
  $whitelist = Array("abs", "max", "min",
                     "exp", "sqrt", "hypot",
                     "sin", "sinh", "cos", "cosh", "tan", "tanh", "asin", "asinh", "acos", "acosh", "atan", "atan2", "atanh",
                     "log", "log10",
                     "pi", "pow",
                     "rad2deg",
                     "round", "ceil",  "floor", "fmod",
            );
  $functionname = substr($functioncall[0], 0, strcspn($functioncall[0], '('));

  if (in_array($functionname, $whitelist)) {
    return $functioncall[0];
  } else {
    $repeat = 1;
    return '';
  }
}


function fill_map($block, &$map) {
  // the variables are interpreted line-wise. If a line begins with a space, it's interpreted as 
  // being part of the previous definition
  $lines = explode("\n", $block);
  $key = '';
  $value = '';
  foreach ($lines as $line) {
    if (trim($line) == '') {
      // ignore empty lines
      continue;
    } else if (($line[0] == ' ') && ($key != '')) {
      $value .= trim($line);
    } else {
      if (key != '') {
        $map[$key] = $value;
        $key = '';
        $value = '';
      }
      list($key, $value) = explode('=', $line, 2);
      $key = trim($key);
      $value = trim($value);
    }
  }
  if (key != '') {
    $map[$key] = $value;
  }
}


class action_plugin_mytemplate extends DokuWiki_Action_Plugin {

    public $variables = array();
    public $maps = array();

    function getInfo(){
        return array(
            'author' => 'Sebastian Herbord',
            'email'  => 'sherb@gmx.net',
            'date'   => '2010-04-04',
            'name'   => 'My Template',
            'desc'   => 'Allows definition of complex page templates.',
            'url'    => '',
        );
    }

    function register(& $controller) {
        $controller->register_hook('PARSER_WIKITEXT_PREPROCESS', 'BEFORE', $this, 'handle_content_display', array ());
    }

    function do_calculate($formula) {
      // perform calculations
      $repeat = true;
      while ($repeat) {
        $repeat = false;
        // apply our whitelist to everything that looks like a php function call to prevent nasty tricks
        $formula = preg_replace_callback("/([a-zA-Z][a-zA-Z0-9_]*\([^\)]*\))/", "apply_whitelist", $formula);
      }
      $varmatches = array();
      preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', $formula, $varmatches, PREG_SET_ORDER);
      foreach ($varmatches as $var) {
        if ($this->variables[$var[1]]) {
          $formula = str_replace($var[0], $this->variables[$var[1]], $formula);
        } else {
          $formula = str_replace($var[0], '0', $formula);
        }
      }
      return eval("return $formula;");
    } 

    function do_lookrange($map, $pos) {
      // the map is assumed to have numeric, non-consecutive indices. $pos is rounded down to the nearest
      // index
      ksort($map);
      reset($map);
      $previous = key($map);
      foreach (array_keys($map) as $key) {
        if ($pos < $key) {
          break;
        } else {
          $previous = $key;
        }
      }
      return $map[$previous];
    }

    function do_list($variable, $format, $minrows) {
      // construct a table from a list
      $table = '';
      $tuples = array();
      preg_match_all("/\((([^()]*|\([^\)]*\))*)\),?/", $variable, $tuples, PREG_SET_ORDER);
      $numrows = 0;
      foreach ($tuples as $tuple) {
        $fields = explode(',', $tuple[1]);
        $row = $format;
        $pos = count($fields) - 1;
        for ($pos = count($fields) - 1; $pos >= 0; $pos--) {
          $row = str_replace('@' . $pos, trim($fields[$pos], ' \''), $row);
        }
        if ($table != '') $table .= "\n";
        $table .= $row;
        $numrows++;
      }
      if (!empty($minrows)) {
        $emptyrow = preg_replace('/\s*[^|\^]+[^|\^ ]*/', ' \\\\\\ ', $format);
        while ($numrows < $minrows) {
          if ($table != '') $table .= "\n";
          $table .= $emptyrow;
          $numrows++;
        }
      }
      return $table;
    }

    function substitute(&$text, $maxpasses) {
      // now for the fun part: replacement time
      $matches = array();

      // if maxpasses is 0, we repeat this until no replacements were made, otherwise we repeat until
      // maxpasses is reached
      $replacements = 1;
      for ($pass = 0; $replacements != 0 && ($maxpasses == -1 || $pass <= $maxpasses); $pass++) {
        if ($maxpasses == -1) {
          $replacements = 0;
        }

        $repls = array();

        preg_match_all("/~~(?P<function>VAR|LOOK|LOOKRANGE|CALC|COUNT|LIST|IF|REPLACE|NOINCLUDE)\((?P<pass>[0-9]+)(,(?P<assignment_target>[A-Za-z_][A-Za-z0-9_]*))?\):(?P<param1>([^:~]+|(?R))*)(:(?P<param2>([^:~]+|(?R))*))?(:(?P<param3>([^:~]+|(?R))*))?~(?P<store_only>!)?~/", $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
          $function          = $match["function"][0];
          $targetpass        = $match["pass"][0];
          $assignment_target = $match["assignment_target"][0];
          $param1            = trim($match["param1"][0]);
          $param2            = trim($match["param2"][0]);
          $param3            = trim($match["param3"][0]);
          $store_only        = $match["store_only"][0]; // if set, the result is not written to the text

          $offset = $match[0][1];
          $len = strlen($match[0][0]);

          if ($targetpass != $pass) {
            continue;
          }
          // parameters may themselves contain substitutions-tags. substitute now.
          $this->substitute($param1, $pass);
          if ($function != 'IF') {
            if (!empty($param2)) $this->substitute($param2, $pass);
            if (!empty($param3)) $this->substitute($param3, $pass);
          }

          switch ($function) {  
            case 'LOOK':
              if (array_key_exists($param1, $this->maps)) {
                $value = $this->maps[$param1][$param2];
              } else {
                dbg('no map named ' . $param1);
                $value = '';
              }
            break;
            case 'LOOKRANGE':
              if (array_key_exists($param1, $this->maps)) {
                $value = $this->do_lookrange($this->maps[$param1], $param2);
              } else {
                dbg('no map named ' . $param1);
                $value = '';
              }
            break;
            case 'CALC':
              $value = $this->do_calculate($param1);
            break;
            case 'VAR':
              $value = $param1;
              $varmatches = array();
              preg_match_all("/[A-Za-z_][A-Za-z0-9_]*/", $param1, $varmatches, PREG_SET_ORDER);
              foreach ($varmatches as $var) {
                $value = str_replace($var[0], $this->variables[$var[0]], $value);
              }
            break;
            case 'COUNT':
              $temp = array();
              $value = preg_match_all('\'' . addslashes($param1) . '\'', $param2, $temp);
            break;
            case 'LIST':
              $value = $this->do_list($this->variables[$param1], trim($param2, '[]'), $param3);
            break;
            case 'IF':
              if ($this->do_calculate($param1)) {
                $this->substitute($param2, $pass);
                $value = $param2;
              } else {
                $this->substitute($param3, $pass);
                $value = $param3;
              }
            break;
            case 'REPLACE':
              $value = preg_replace('\'' . addslashes($param1) . '\'', $param2, $param3);
            break;
            case 'NOINCLUDE':
              // nop
            break;
          }
          if ($assignment_target) {
            $this->variables[$assignment_target] = $value;
          }
          if ($store_only) {
            $repls[] = array($offset, $len, '');
          } else {
            $repls[] = array($offset, $len, $value);
          }
          $replacements++;
        }

        krsort($repls);

        foreach($repls as $repl) {
          $text = substr_replace($text, $repl[2], $repl[0], $repl[1]);
        }
      }
    }


    function handle_content_display(&$event, $params) {
      global $ACT, $INFO;
      if (($ACT != 'show') && ($ACT != 'save')) {
        return;
      }

      if (strstr($event->data, '~~TEMPLATE~~')) {
        $event->data = str_replace('~~TEMPLATE~~', '', $event->data);
        return;
      }

      $page = $event->data;

      // integrate all includes
      $includematches = array();
      preg_match_all('/\[INCLUDE:([^\]]*)\]/', $page, $includematches, PREG_SET_ORDER);
      foreach ($includematches as $includematch) {
        $includeid = $includematch[1];
        $file = wikiFN($includeid, '');
        if (@file_exists($file)) {
          $content = io_readWikiPage($file, $includeid);
        }
        if (!$content) {
          $page = str_replace($includematch[0], "include \"$includeid\" not found", $page);
          continue;
        }
        $page = str_replace($includematch[0], $content, $page);
      }
 
      $page = str_replace('~~TEMPLATE~~', '', $page);

      // interpret and remove all maps and variable blocks
      $mapblocks = array();
      preg_match_all('/\[MAPS\](.*)?\[ENDMAPS\]/sm', $page, $mapblocks, PREG_SET_ORDER);
      foreach($mapblocks as $mapblock) {
        fill_map($mapblock[1], $this->maps);
        // at this point, maps are stored as strings, we need to convert them
        foreach(array_keys($this->maps) as $mapname) {
          $list = explode(',', $this->maps[$mapname]);
          $map  = array();
          foreach ($list as $field) {
            if ($pos = strpos($field, '=')) {
              $map[trim(substr($field, 0, $pos))] = trim(substr($field, $pos + 1));
            } else {
              // no key found => append
              $map[] = trim($field);
            }
          }
          $this->maps[$mapname] = $map;
        }
        $page = str_replace($mapblock[0], '', $page);
      }

      $variableblocks = array();
      preg_match_all('/\[VARIABLES\](.*)?\[ENDVARIABLES\]/sm', $page, $variableblocks, PREG_SET_ORDER);
      foreach ($variableblocks as $variableblock) {
        fill_map($variableblock[1], $this->variables);
        $page = str_replace($variableblock[0], '', $page);
      }

      // invoke the substitution
      $this->substitute($page, -1);

      // finally, replace the page with the one we generated
      $event->data = $page;
      return true;
    }
}

