<?php
/**
 * @license    GPL 3 (http://www.gnu.org/licenses/gpl.html)
 * @author     Szymon Olewniczak <(my first name) [at] imz [dot] re>
 * @author     Cejka Rudolf <cejkar@fit.vutbr.cz>
 */

// must be run within DokuWiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once DOKU_PLUGIN.'syntax.php';

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_filterrss extends DokuWiki_Syntax_Plugin {

    function getPType(){
       return 'block';
    }

    function getType() { return 'substition'; }
    function getSort() { return 32; }


    function connectTo($mode) {
		$this->Lexer->addSpecialPattern('\[filterrss.*?\]',$mode,'plugin_filterrss');
    }

    function handle($match, $state, $pos, Doku_Handler $handler) {
		// prepare data array
        $data = [
            'url' => null,
            'conditions' => [],
            'order_by' => '',
            'desc' => false,
            'limit' => 99999999,
            'render' => 'pagelist'
        ];

		// prepare the match
		// Remove ']' from the end
		$match = substr($match, 0, -1);
		// Remove '[filterrss'
		$match = substr($match, 10);

		// no only ' url condition1 && condition2 && condition3 ORDER BY field DESC/ASC LIMIT 10' is left

		// prepare the operands and arguments template
		$known_fileds = array('pubDate', 'title', 'description', 'link');
		$opposite_signs = array('>' => '<', '<' => '>', '>=' => '<=', '<=' => '>=');

		$query = preg_split('/order by/i', $match);

		if( isset( $query[1] ) )
		{
			$sort = trim($query[1]); // = 'field DESC/ASC LIMIT 10'

			//ASC ist't isteresting -> is the default
			$sort = str_ireplace('asc', '', $sort);

			if(stripos($sort, 'desc') !== false)
			{
				$sort = str_ireplace('desc', '', $sort);
				$data['desc'] = true;
			}

			$limit_reg = '/limit\s*([0-9]*)/i';
			if(preg_match($limit_reg, $sort, $matches) )
			{
				$data['limit'] = (int)$matches[1];
				$sort = preg_replace($limit_reg, '', $sort);
			}

			$data['order_by'] = trim($sort); // = field (e.g. 'pubDate')
		} else
		{
			$limit_reg = '/limit\s*([0-9]*)\s*$/i';
			if(preg_match($limit_reg, $args, $matches) )
			{
				$data['limit'] = (int)$matches[1];
				$query = preg_replace($limit_reg, '', $query);
			}

		}


		$args = trim($query[0]); // = 'url condition1 && condition2 && condition3'
		$exploded = explode(' ', $args); // = [0 => url, 1 => condition1, ...]
		
		$data['url'] = $exploded[0];

		// we have not enough arguments for conditions
		if(count($exploded) < 2)
		{
			return $data;
		}

		array_shift($exploded);

		$conditions = implode('', $exploded);

		$cond_array = explode('&&', $conditions);

		$cond_output = array();

		foreach($cond_array as $cond)
		{
			preg_match('/(.*?)(>|<|=|>=|<=)+(.*)/', $cond, $res);
			if(in_array($res[1], $known_fileds))
			{
			$name = $res[1];
			$value = $res[3];
			$sign = $res[2];
			} elseif(in_array($res[3], $known_fileds))
			{
			$name = $res[3];
			$value = $res[1];
			$sign = $opposite_signs[$res[2]];
			} else
			{
			continue;
			}

			//remove "" and ''
			$value = str_replace(array('"', "'"), '', $value);

			if(!isset($cond_output[$name]))
			$cond_output[$name] = array();

			array_push($cond_output[$name], array($sign, $value));
		}
		$data['conditions'] = $cond_output;

		return $data;
    }

    function render($mode, Doku_Renderer $renderer, $data) {
        if($mode == 'xhtml') {

	    $filterrss =& plugin_load('helper', 'filterrss');

	    $rss = simplexml_load_file($data['url']);
	    $rss_array = array();

	    if(!$rss)
	    {
			$renderer->doc .= 'Cannot load rss feed.';
		}

		$items = $rss->entry;
		$items_count = 0;
		foreach($items as $item)
		{
			if( $items_count >= $data['limit'])
			break;
			$items_count++;
			$jump_this_entry = false;
			foreach($data['conditions'] as $entry => $conditions)
			{
			switch($entry)
			{
				case 'pubDate':
				foreach($conditions as $comparison)
				{
					$left = strtotime($item->updated);
					$right = strtotime($comparison[1]);
					switch($comparison[0])
					{
					case '>':
						if(!($left > $right))
						{
						$jump_this_entry = true;
						break;
						}
					break;
					case '<':
						if(!($left < $right))
						{
						$jump_this_entry = true;
						break;
						}
					break;
					case '>=':
						if(!($left >= $right))
						{
						$jump_this_entry = true;
						break;
						}
					break;
					case '<=':
						if(!($left <= $right))
						{
						$jump_this_entry = true;
						break;
						}
					break;
					case '=':
						if(!($left == $right))
						{
						$jump_this_entry = true;
						break;
						}
					break;
					}
				}
				break;
				case 'title':
				case 'description':
				case 'link':
				foreach($conditions as $comparison)
				{
					$subject = $item->$entry;

					//simple regexp option
					$pattern ='/'. str_replace('%', '.*', preg_quote($comparison[1])).'/';

					switch($comparison[0])
					{
					case '=':
						if(!preg_match($pattern, $subject))
						{
						$jump_this_entry = true;
						break;
						}
					break;
					}
				}
				break;
			}

			if($jump_this_entry == true)
				break;
			}
			if($jump_this_entry == false)
			{
			$entry = array();
			$entry['title'] = $item->title;
			$entry['link'] = $item->link;
			$entry['pubDate'] = strtotime($item->updated);
			$entry['description'] = $item->summary->asXML();
			
			array_push($rss_array, $entry);

			}
		}

		if(!empty($data['order_by']))
		{
			switch($data['order_by'])
			{
			case 'pubDate':
				$rss_array = $filterrss->int_sort($rss_array, $data['order_by']);
			break;
			case 'title':
			case 'description':
			case 'link':
				$rss_array = $filterrss->nat_sort($rss_array, $data['order_by']);
			break;
			}
			if($data['desc'])
			{
			$rss_array = array_reverse($rss_array);
			} 
		}

		switch($data['render']) {
			case 'list':
				foreach($rss_array as $entry)
				{
					$renderer->doc .= '<div class="filterrss_plugin">';
					$renderer->doc .= '<a href="'.$entry['link'].'">'.$entry['title'].'</a><br>';
					$renderer->doc .= '<span>'.date('d.m.Y',$entry['pubDate']).'</span>';
					if($this->getConf('bbcode') == true)
					{
					$renderer->doc .= '<p>'.$filterrss->bbcode_parse($entry['description']).'</p>';
					} else
					{
					$renderer->doc .= '<p>'.$entry['description'].'</p>';
					}
					$renderer->doc .= '</div>';
				}
				break;
			case 'pagelist':
				/** @var helper_plugin_pagelist $pagelist */
				$pagelist = @plugin_load('helper', 'pagelist');
				if ($pagelist) {
					$pagelist->setFlags(['desc','header','nouser','nofirsthl']);
					$pagelist->startList();
					foreach ($rss_array as $entry) {
						$page['id'] = $entry['link']['href'];
						$page['date'] = $entry['pubDate'];
						$page['external'] = true;
						$page['description'] = $entry['description'];
						$page['title'] = $entry['title'];

						$pagelist->addPage($page);
					}
					$renderer->doc .= $pagelist->finishList();
				}
				break;
		}

	    return true;

        } elseif ($mode == "metadata") {
	    $renderer->meta['plugin_filterrss']['purge'] = true;
	}
        return false;
    }
}
