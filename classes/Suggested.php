<?php

class Suggested
{
    /**
     * Tag suggestions for the search field: tags with a word that starts with the last typed word,
     * most used tags first (as the former Manticore "suggested" index answered).
     *
     * @return string[] up to 10 tag names
     */
    public static function search($term)
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim((string)$term)), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) {
            return array();
        }
        $prefix = end($words);

        Search::registerFunctions();

        return R::getCol(
            "select tag.name from tag where tag.name like ? escape '\\' and wordprefix(tag.name, ?) ".
            'order by (select count(*) from gallery_tag where gallery_tag.tag_id = tag.id) desc, tag.id limit 10',
            array('%'.addcslashes($prefix, '\\%_').'%', $prefix)
        );
    }
}
