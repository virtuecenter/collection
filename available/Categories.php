<?php
/*
 * @version .3
 * @link https://raw.github.com/Opine-Org/Collection/master/available/Categories.php
 * @mode upgrade
 *
 * .3 don't index sub documents for now
 */
namespace Collection;

class Categories {
    public $publishable = false;
    public $singular = 'category';

    public function index ($document) {
        $depth = substr_count($document['dbURI'], ':');
        if ($depth > 1) {
            return false;
        }
        return [
            'title' => $document['title'], 
            'description' => '', 
            'image' => isset($document['image']) ? $document['image'] : '', 
            'tags' => [], 
            'categories' => [], 
            'date' => date('c', $document['created_date']->sec) 
        ];
    }
}