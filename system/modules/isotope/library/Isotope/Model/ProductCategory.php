<?php

/*
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009 - 2019 terminal42 gmbh & Isotope eCommerce Workgroup
 *
 * @link       https://isotopeecommerce.org
 * @license    https://opensource.org/licenses/lgpl-3.0.html
 */

namespace Isotope\Model;


use Contao\Date;

class ProductCategory extends \Model
{

    /**
     * Name of the current table
     * @var string
     */
    protected static $strTable = 'tl_iso_product_category';

    /**
     * Gets the options for the query for the "findByPidForPublishedPages" method.
     *
     * @param       $intProduct
     * @param array $arrOptions
     *
     * @return array
     */
    public static function getFindByPidForPublishedPagesOptions($intProduct, array $arrOptions = array())
    {
        $t = static::getTable();
        $having = "page_id__type!='error_403' AND page_id__type!='error_404'";

        if (!BE_USER_LOGGED_IN) {
            $time = Date::floorToMinute();
            $having .= " AND (page_id__start='' OR page_id__start<'$time') AND (page_id__stop='' OR page_id__stop>'" . ($time + 60) . "') AND page_id__published='1'";
        }

        $arrOptions = array_merge(
            array(
                'column' => array("$t.pid=?"),
                'value'  => array($intProduct),
                'eager'  => true,
                'having' => $having,
                'return' => 'Collection'

            ),
            $arrOptions
        );

        return $arrOptions;
    }

    /**
     * Find categories by product id if the respective page is published
     *
     * @param int   $intProduct
     * @param array $arrOptions
     *
     * @return \Model\Collection|null
     */
    public static function findByPidForPublishedPages($intProduct, array $arrOptions = array())
    {
        $arrOptions = static::getFindByPidForPublishedPagesOptions($intProduct, $arrOptions);

        return parent::find($arrOptions);
    }
}
