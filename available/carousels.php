<?php
/*
 * @version .4
 * @link https://raw.github.com/virtuecenter/collection/master/available/carousels.php
 * @mode upgrade
 */
namespace Collection;

class carousels {
	public $publishable = false;
	public $singular = 'carousel';
	public $permalink = '/carousel/';

	public function index ($document) {
		$depth = substr_count($document['dbURI'], ':');
		if ($depth > 1) {
			return false;
		}
		return [
			'title' => $document['title'], 
			'description' => $document['description'], 
			'image' => '',
			'tags' => isset($document['tags']) ? $document['tags'] : [], 
			'categories' => [],
			'date' => date('c', $document['created_date']->sec) 
		];
	}
}