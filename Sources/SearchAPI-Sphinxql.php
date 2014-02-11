<?php
/**********************************************************************************
* SearchAPI-Sphinxql.php                                                          *
***********************************************************************************
* SMF: Simple Machines Forum                                                      *
* Open-Source Project Inspired by Zef Hemel (zef@zefhemel.com)                    *
* =============================================================================== *
* Software Version:           SMF 2.0 RC4                                         *
* Software by:                Simple Machines (http://www.simplemachines.org)     *
* Copyright 2006-2009 by:     Simple Machines LLC (http://www.simplemachines.org) *
*           2001-2006 by:     Lewis Media (http://www.lewismedia.com)             *
* Support, News, Updates at:  http://www.simplemachines.org                       *
***********************************************************************************
* This program is free software; you may redistribute it and/or modify it under   *
* the terms of the provided license as published by Simple Machines LLC.          *
*                                                                                 *
* This program is distributed in the hope that it is and will be useful, but      *
* WITHOUT ANY WARRANTIES; without even any implied warranty of MERCHANTABILITY    *
* or FITNESS FOR A PARTICULAR PURPOSE.                                            *
*                                                                                 *
* See the "license.txt" file for details of the Simple Machines license.          *
* The latest version can always be found at http://www.simplemachines.org.        *
**********************************************************************************/

if (!defined('SMF'))
	die('Hacking attempt...');

/*
	int searchSort(string $wordA, string $wordB)
		- callback function for usort used to sort the fulltext results.
		- the order of sorting is: large words, small words, large words that
		  are excluded from the search, small words that are excluded.
*/

class sphinxql_search
{
	// This is the last version of SMF that this was tested on, to protect against API changes.
	public $version_compatible = 'SMF 2.0';
	// This won't work with versions of SMF less than this.
	public $min_smf_version = 'SMF 2.0 Beta 2';
	// Is it supported?
	public $is_supported = true;

	// What databases support the custom index?
	protected $supported_databases = array('mysql');

	// Nothing to do...
	public function __construct()
	{
		global $db_type, $txt, $modSettings;

		$txt['search_index_sphinxql'] = 'SphinxQL';
		$txt['search_index_sphinxql_desc'] = 'The admin panel only allows to switch between search indexes. To adjust further SphinxQL settings, use the sphinx_config.php tool.';

		// Is this database supported?
		if (!in_array($db_type, $this->supported_databases) || empty($modSettings['sphinxql_searchd_port']))
		{
			$this->is_supported = false;
			return;
		}
	}

	// Check whether the search can be performed by this API.
	public function supportsMethod($methodName, $query_params = null)
	{
		switch ($methodName)
		{
			case 'searchSort':
			case 'prepareIndexes':
			case 'indexedWordQuery':
			case 'searchQuery':
				return true;
			break;

			default:

				// All other methods, too bad dunno you.
				return false;
			return;
		}
	}

	// This function compares the length of two strings plus a little.
	public function searchSort($a, $b)
	{
		global $modSettings, $excludedWords;

		$x = strlen($a) - (in_array($a, $excludedWords) ? 1000 : 0);
		$y = strlen($b) - (in_array($b, $excludedWords) ? 1000 : 0);

		return $x < $y ? 1 : ($x > $y ? -1 : 0);
	}

	// Do we have to do some work with the words we are searching for to prepare them?
	public function prepareIndexes($word, &$wordsSearch, &$wordsExclude, $isExcluded)
	{
		global $modSettings;

		$subwords = text2words($word, null, false);

		$fulltextWord = count($subwords) === 1 ? $word : '"' . $word . '"';
		$wordsSearch['indexed_words'][] = $fulltextWord;
		if ($isExcluded)
			$wordsExclude[] = $fulltextWord;
	}

	// This has it's own custom search.
	public function searchQuery($search_params, $search_words, $excluded_words, &$participants, &$search_results)
	{
		global $user_info, $context, $modSettings;

		// Only request the results if they haven't been cached yet.
		if (($cached_results = cache_get_data('search_results_' . md5($user_info['query_see_board'] . '_' . $context['params']))) === null)
		{
			// Create an instance of the sphinx client and set a few options.
			$mySphinx = mysql_connect(($modSettings['sphinx_searchd_server'] == 'localhost' ? '127.0.0.1' : $modSettings['sphinx_searchd_server']) . ':' . (int) $modSettings['sphinxql_searchd_port']);

			// Compile different options for our query
			$query = 'SELECT * FROM smf_index';

			// Construct the (binary mode) query.
			$where_match = $this->_constructQuery($search_params['search']);
			// Nothing to search, return zero results
			if (trim($where_match) == '')
				return 0;

			if ($search_params['subject_only'])
				$where_match = '@subject ' . $where_match;

			$query .= ' WHERE MATCH(\'' . $where_match . '\')';

			// Set the limits based on the search parameters.
			$extra_where = array();
			if (!empty($search_params['min_msg_id']) || !empty($search_params['max_msg_id']))
				$extra_where[] = '@id >= ' . $search_params['min_msg_id'] . ' AND @id <=' . (empty($search_params['max_msg_id']) ? (int) $modSettings['maxMsgID'] : $search_params['max_msg_id']);
			if (!empty($search_params['topic']))
				$extra_where[] = 'id_topic = ' . (int) $search_params['topic'];
			if (!empty($search_params['brd']))
				$extra_where[] = 'id_board IN (' . implode(',', $search_params['brd']) . ')';
			if (!empty($search_params['memberlist']))
				$extra_where[] = 'id_member IN (' . implode(',', $search_params['memberlist']) . ')';

			if (!empty($extra_where))
				$query .= ' AND ' . implode(' AND ', $extra_where);

			// Put together a sort string; besides the main column sort (relevance, id_topic, or num_replies), add secondary sorting based on relevance value (if not the main sort method) and age
			$sphinx_sort = ($search_params['sort'] === 'id_msg' ? 'id_topic' : $search_params['sort']) . ' ' . strtoupper($search_params['sort_dir']) . ($search_params['sort'] === 'relevance' ? '' : ', relevance desc') . ', poster_time DESC';
			// Grouping by topic id makes it return only one result per topic, so don't set that for in-topic searches
			if (empty($search_params['topic']))
				$query .= ' GROUP BY id_topic WITHIN GROUP ORDER BY ' . $sphinx_sort;
			$query .= ' ORDER BY ' . $sphinx_sort;

			$query .= ' LIMIT 0,' . (int) $modSettings['sphinx_max_results'];

			// Execute the search query.
			$request = mysql_query($query, $mySphinx);

			// Can a connection to the daemon be made?
			if ($request === false)
			{
				// Just log the error.
				if (mysql_error($mySphinx))
					log_error(mysql_error($mySphinx));
				fatal_lang_error('error_no_search_daemon');
			}

			// Get the relevant information from the search results.
			$cached_results = array(
				'matches' => array(),
			);
			if (mysql_num_rows($request) != 0)
				while($match = mysql_fetch_assoc($request))
					$cached_results['matches'][$match['id']] = array(
						'id' => $match['id_topic'],
						'relevance' => round($match['relevance'] / 10000, 1) . '%',
						'num_matches' => empty($search_params['topic']) ? $match['@count'] : 0,
						'matches' => array(),
					);
			mysql_free_result($request);
			mysql_close($mySphinx);

			$cached_results['total'] = count($cached_results['matches']);
			// Store the search results in the cache.
			cache_put_data('search_results_' . md5($user_info['query_see_board'] . '_' . $context['params']), $cached_results, 600);
		}

		$participants = array();
		foreach (array_slice(array_keys($cached_results['matches']), $_REQUEST['start'], $modSettings['search_results_per_page']) as $msgID)
		{
			$context['topics'][$msgID] = $cached_results['matches'][$msgID];
			$participants[$cached_results['matches'][$msgID]['id']] = false;
		}

		// Sentences need to be broken up in words for proper highlighting.
		$search_results = array();
		foreach ($search_words as $orIndex => $words)
			$search_results = array_merge($search_results, $search_words[$orIndex]['subject_words']);

		return $cached_results['total'];
	}

	/**
	 * Constructs a binary mode query to pass back to sphinx
	 *
	 * @param string $string The user entered query to construct with
	 * @return string A binary mode query
	 */
	function _constructQuery($string)
	{
		$keywords = array('include' => array(), 'exclude' => array());

		// Split our search string and return an empty string if no matches
		if (!preg_match_all('~ (-?)("[^"]+"|[^" ]+)~', ' ' . $string , $tokens, PREG_SET_ORDER))
			return '';

		// First we split our string into included and excluded words and phrases
		$or_part = FALSE;
		foreach ($tokens as $token)
		{
			// Strip the quotes off of a phrase
			if ($token[2][0] == '"')
			{
				$token[2] = substr($token[2], 1, -1);
				$phrase = TRUE;
			}
			else
				$phrase = FALSE;

			// Prepare this token
			$cleanWords = $this->_cleanString($token[2]);

			// Explode the cleanWords again incase the cleaning put more spaces into it
			$addWords = $phrase ? array('"' . $cleanWords . '"') : preg_split('~ ~u', $cleanWords, NULL, PREG_SPLIT_NO_EMPTY);

			if ($token[1] == '-')
				$keywords['exclude'] = array_merge($keywords['exclude'], $addWords);

			// OR'd keywords (we only do this if we have something to OR with)
			elseif (($token[2] == 'OR' || $token[2] == '|') && count($keywords['include']))
			{
				$last = array_pop($keywords['include']);
				if (!is_array($last))
					$last = array($last);
				$keywords['include'][] = $last;
				$or_part = TRUE;
				continue;
			}

			// AND is implied in a Sphinx Search
			elseif ($token[2] == 'AND' || $token[2] == '&')
				continue;

			// If this part of the query ended up being blank, skip it
			elseif (trim($cleanWords) == '')
				continue;

			// Must be something they want to search for!
			else
			{
				// If this was part of an OR branch, add it to the proper section
				if ($or_part)
					$keywords['include'][count($keywords['include']) - 1] = array_merge($keywords['include'][count($keywords['include']) - 1], $addWords);
				else
					$keywords['include'] = array_merge($keywords['include'], $addWords);
			}

			// Start fresh on this...
			$or_part = FALSE;
		}

		// Let's make sure they're not canceling each other out
		if (!count(array_diff($keywords['include'], $keywords['exclude'])))
			return '';

		// Now we compile our arrays into a valid search string
		$query_parts = array();
		foreach ($keywords['include'] as $keyword)
			$query_parts[] = is_array($keyword) ? '(' . implode(' | ', $keyword) . ')' : $keyword;

		foreach ($keywords['exclude'] as $keyword)
			$query_parts[] = '-' . $keyword;

		return implode(' ', $query_parts);
	}

	/**
	 * Cleans a string of everything but alphanumeric characters
	 *
	 * @param string $string A string to clean
	 * @return string A cleaned up string
	 */
	function _cleanString($string)
	{
		global $smcFunc;

		// Decode the entities first
		$string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');

		// Lowercase string
		$string = $smcFunc['strtolower']($string);

		// Fix numbers so they search easier (phone numbers, SSN, dates, etc)
		$string = preg_replace('~([[:digit:]]+)\pP+(?=[[:digit:]])~u', '', $string);

		// Last but not least, strip everything out that's not alphanumeric
		$string = preg_replace('~[^\pL\pN]+~u', ' ', $string);

		return $string;
	}
}

?>