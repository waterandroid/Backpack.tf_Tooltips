<?php
/*
 *   TF2 Tooltips
 *   Copyright (C) 2012-2013  Jake "rannmann" Forrester
 *
 *   This program is free software: you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
include_once('config.php');

$link = mysql_connect($server, $dbuser, $dbpass) or die(mysql_error());
mysql_select_db($database) or die(mysql_error());

$itemname = mysql_real_escape_string($_POST['itemname']); // Supplied from javascript

$colors = array( // Hex colors for name display
    1  => '#4D7455', // Genuine
    3  => '#476291', // Vintage
    5  => '#8650AC', // Unusual
    6  => '#FFD700', // Unique
    7  => '#70B04A', // Community
    8  => '#A50F79', // Valve
    9  => '#70B04A', // Self-Made
    11 => '#CF6A32', // Strange
    13 => '#38F3AB', // Haunted
    14 => '#A00' // Collector's
);

$removes = array( // Regular expressions to remove when performing the query
    '/^The /iu',
    '/^Vintage /iu',
    '/^V. /iu',
    '/^Genuine /iu',
    '/^G. /iu',
    '/^Strange /iu',
    '/^S. /iu',
    '/^Haunted /iu',
    '/^H. /iu',
    "/^Collect[oe]r(\\\')?s? /iu",
    '/^Unusual /iu',
    '/^U. /iu',
    '/^Community /iu',
    '/^C. /iu',
    '/^Valve /iu',
    '/^Self-Made /iu'
);

/* Given an item quality, determines if item is tradable */
function isTradable($quality) {
  switch ($quality) {
    case '7': // Community
    case '8': // Valve
    case '9': // Self-Made
      return false;
    
    default:
      return true;
  }
}

/* Set the quality of the item */
if ( // Vintage
      // Error handling for valve's stupid idea of putting "vintage" in the item names
      ( !preg_match('/^Vintage Merryweather/iu', $itemname) &&  !preg_match('/^Vintage Tyrolean/iu', $itemname) &&
      preg_match('/^Vintage /iu', $itemname) ) || 
      preg_match('/^V. /iu', $itemname)) { $quality = 3; } 
elseif ( // Genuine
      preg_match('/^Genuine /iu', $itemname) || 
      preg_match('/^G. /iu', $itemname)) { $quality = 1; } 
elseif (// Strange
      // Error handling for "strange" in item names
      ( !preg_match('/^Strange Part/iu', $itemname) &&  !preg_match('/^Strange Bacon/iu', $itemname) &&
      preg_match('/^Strange /iu', $itemname) ) ||  
      preg_match('/^S. /iu', $itemname)) { $quality = 11; } 
elseif (// Haunted
      preg_match('/^Haunted /iu', $itemname) ||  
      preg_match('/^H. /iu', $itemname)) { $quality = 13; } 
elseif (// Unusual
      preg_match('/^Unusual /iu', $itemname) ||  
      preg_match('/^U. /iu', $itemname)) { $quality = 5; } 
elseif (// Collector's
      preg_match("/^Collect[oe]r(\\\')?s? /iu", $itemname)) { $quality = 14; }
elseif (// Community
      preg_match('/^Community /iu', $itemname) ||  
      preg_match('/^C. /iu', $itemname)) { $quality = 7; } 
elseif ( // Valve (developer)
      preg_match('/^Valve /iu', $itemname)) { $quality = 8; } 
elseif (
      preg_match('/^Self-Made /iu', $itemname)) { $quality = 9; } // Self-Made
else { $quality = 6; } // Assume everything else is "Unique"

/* Check for effects */
if ($quality  == 5) {
  /* Bear with me... this is super hacksy because I'm not a DBA.
   * This loops through each word in a sentence
   * performing a wildcard "LIKE" match and incrementing 
   * a counter.  The results are sorted by the number of matches
   * and the one with the greatest matches is returned.
   * There's no standardized way of traders using effect names,
   * so this will just "magically" figure it out. */
  $q = 'SELECT * , ';
  foreach(explode(' ',$itemname) as $word) {
    if (($word != "U.") && ($word != "Unusual")) {
      $q .= 'IF( name LIKE "%'.preg_replace("/[^A-Za-z0-9 ]/", '', $word).'%", 1, 0 ) + ';
    }
  }
  $q = substr($q,0,-2); // Remove the last "+ "
  $q .= 'AS found
  FROM  `particle_schema` 
  ORDER BY found DESC,
           LENGTH(name) ASC
  LIMIT 1';
  $q = mysql_query($q) or die(mysql_error());
  $q = mysql_fetch_row($q);
  $effect = array($q[0], $q[1]); // Effect ID, Effect Name
}

/* Special case for crates, because backpack.tf has mean json */
elseif (preg_match('/Crate /iu',$itemname) && !preg_match('/Key/iu',$itemname)) {
  foreach(explode(' ',$itemname) as $word) {
    if (preg_match('/^\#/',$word)) {
      $effect = array(substr($word,1),'Series ' . $word); // 41, Series #41
      break;
    }
  }
  $itemname = 'Mann Co. Supply Crate';
}


$itemname = preg_replace($removes,'',$itemname); // remove all prefixes


if ($quality == 5) {
    /*
    This is pretty confusing, so here's what it's creating.  This had to be done because of hat effects as noted above.
    SELECT item.item_name, item.proper_name, item.image_url, item.item_description, item.holiday_restriction, bp.quality, bp.effect, bp.value, 
    IF( item.item_name LIKE "%Unusual%", 1, 0 ) + 
    IF( item.item_name LIKE "%Stout%", 1, 0 ) + 
    IF( item.item_name LIKE "%Shako%", 1, 0 ) + 
    IF( item.item_name LIKE "%Searing%", 1, 0 ) + 
    IF( item.item_name LIKE "%Flames%", 1, 0 ) AS found
    FROM  `item_schema` as item ,  `backpack` as bp
    WHERE item.defindex = bp.defindex
    AND bp.quality = 5
    AND bp.effect = 15
    ORDER BY found DESC
    */
    $q = 'SELECT item.item_name, item.proper_name, item.image_url, item.item_description, item.holiday_restriction, bp.quality, bp.effect, bp.value, ';
    foreach(explode(' ',$itemname) as $word) {
        $q .= 'IF( item.item_name LIKE "%'.$word.'%", 1, 0 ) + ';
    }
    $q = substr($q,0,-2); // Remove the last "+ "
    $q .= 'AS found
    FROM  `item_schema` as item ,  `backpack` as bp
    WHERE item.defindex = bp.defindex
    AND bp.quality = '.$quality;
    if (isset($effect)) {
       $q .= ' AND bp.effect = '.$effect[0];
   }
   $q .= ' ORDER BY found DESC
   LIMIT 1';
}
else { /* Makes most searches (non-unusual quality) faster than the above */
   $q = 'SELECT item.item_name, item.proper_name, item.image_url, item.item_description, item.holiday_restriction, bp.quality, bp.effect, bp.value, item.rgb1
   FROM  `item_schema` as item ,  `backpack` as bp
   WHERE item.item_name LIKE "%'.$itemname.'%"
   AND item.defindex = bp.defindex ';
   if (isTradable($quality)) {
    $q .= 'AND bp.quality = '.$quality;
   }
   if (isset($effect)) {
       $q .= ' AND bp.effect = '.$effect[0];
   }
   // Length is used so things like "Jarate" don't return "Emerald Jarate" on accident.  More specificity is better.
   $q .= ' ORDER BY LENGTH(item.item_name) ASC
   LIMIT 1';
}
$q = mysql_query($q) or die(mysql_error());
$q = mysql_fetch_row($q);

if (!$q[0]) { // If the item name could not be found, exit with error message.
    die('Error: Item not found!'); 
}
else {

}

// With the queries done, we can mess with the effect variable
// for community weapons.
if (!$effect && $quality == 7) {
  $effect[1] = "Community Sparkle";
}


echo '
<table style="width:100%">
    <tbody>
        <tr>
            <td style="width:30%">
                <img src="';
                if ( ($q[2] == "http://media.steampowered.com/apps/440/icons/teampaint.1a4edd3437656c11c51bf790de36f84689375217.png") || // Team paint URL
                     ($q[2] == "http://media.steampowered.com/apps/440/icons/paintcan.9046edf23b64960a4084dad29d05d2c902feec78.png") ) { // Regular paint URL
                     echo $paintdir.'Paint_Can_'.strtoupper($q[8]).'.png'; // Paint_Can_FF69B4.png, for example
                }
                else { echo $q[2]; } // Otherwise just use the steam-given URL.
                echo '">
            </td>
            <td style="width:70%">
                <span class="itemName">
                    <span style="color:'.$colors[$quality].'">';
                        if ($q[1]) { echo 'The '; } // ProperName -> "The "
                        echo $q[0].'
                    </span>
                </span>
        <span class="effect">
                    '.$effect[1].'
                </span>
                <span class="description">
                    '.$q[3].'
                </span>
                <span class="value">';
                if (isTradable($quality)) {
                  echo 'Suggested Price: '.$q[7].' ref';
                }
                else {
                  echo 'Untradable';
                }
                echo '</span>
                <span class="restriction">
                    '.str_replace('_',' ',$q[4]).'
                </span>
            </td>
        </tr>
    </tbody>
</table>';
?>