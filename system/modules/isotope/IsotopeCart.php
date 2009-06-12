<?php if (!defined('TL_ROOT')) die('You can not access this file directly!');

/**
 * TYPOlight webCMS
 * Copyright (C) 2005 Leo Feyer
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 2.1 of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at http://www.gnu.org/licenses/.
 *
 * PHP version 5
 * @copyright  Winans Creative / Fred Bliss 2009
 * @author     Andreas Schempp <andreas@schempp.ch>
 * @license    http://opensource.org/licenses/lgpl-3.0.html
 */
 
 
class IsotopeCart extends Model
{
	
	/**
	 * Current object instance (Singleton)
	 * @var object
	 */
	protected static $objInstance;

	/**
	 * Cookie hash value
	 * @var string
	 */
	protected $strHash = '';
	
	/**
	 * Name of the current table
	 * @var string
	 */
	protected $strTable = 'tl_cart';
	
	/**
	 * Cart type. Can be "2" for gift registry.
	 * @var int
	 */
	protected $intType = 1;
	
	/**
	 * Name of the temporary cart cookie
	 * @var string
	 */
	protected $strCookie = 'ISOTOPE_TEMP_CART';
	
	/**
	 * Cache get requests to improve speed. Cart data cannot change without reload...
	 * @var array
	 */
	protected $arrCache = array();
	
	/**
	 * Cache all products for speed improvements
	 * @var array
	 */
	protected $arrProducts = array();
	
	/**
	 * Shipping object if shipping module is set in session
	 * @var object
	 */
	public $Shipping;
	
	/**
	 * Payment object if payment module is set in session
	 * @var object
	 */
	public $Payment;
	
	
	/**
	 * Prevent cloning of the object (Singleton)
	 */
	final private function __clone() {}
	
	
	/**
	 * Return the current object instance (Singleton)
	 * @return object
	 */
	public static function getInstance()
	{
		if (!is_object(self::$objInstance))
		{
			self::$objInstance = new IsotopeCart();
		}

		return self::$objInstance;
	}
	
	
	/**
	 * Return cart data. All data is cached for speed improvement.
	 * 
	 * @access public
	 * @param string $strKey
	 * @return mixed
	 */
	public function __get($strKey)
	{
		// Return from database result
		if (array_key_exists($strKey, $this->arrData))
		{
			return $this->arrData[$strKey];
		}
		
		// Add to cache if not available
		if (!array_key_exists($strKey, $this->arrCache))
		{
			switch( $strKey )
			{
				case 'items':
					$this->arrCache[$strKey] = $this->Database->prepare("SELECT SUM(quantity_requested) AS items FROM tl_cart_items LEFT OUTER JOIN tl_cart ON tl_cart_items.pid=tl_cart.id WHERE tl_cart_items.pid=? AND tl_cart.cart_type_id=?")->execute($this->id, $this->intType)->items;
					break;
					
				case 'products':
					$this->arrCache[$strKey] = $this->Database->prepare("SELECT COUNT(*) AS items FROM tl_cart_items LEFT OUTER JOIN tl_cart ON tl_cart_items.pid=tl_cart.id WHERE tl_cart_items.pid=? AND tl_cart.cart_type_id=?")->execute($this->id, $this->intType)->items;
					break;
					
				case 'subTotal':
					$this->import('Isotope');
					$this->arrCache[$strKey] = $this->calculateTotal($this->Isotope->getProductData($this->getProducts(), array('product_price'), 'product_price'));
					break;
					
				case 'taxTotal':
					// FIXME: currently rounds to 0.05 (swiss francs)
					return (round(($this->subTotal / 107.6 * 7.6)*20)/20);
					break;
					
				case 'taxTotalWithShipping':
					// FIXME: currently rounds to 0.05 (swiss francs)
					return (round((($this->subTotal + ($this->hasShipping ? $this->Shipping->price : 0)) / 107.6 * 7.6)*20)/20);
					break;
					
				case 'grandTotal':
					return ($this->subTotal + ($this->hasShipping ? $this->Shipping->price : 0));
					break;
					
				case 'hasShipping':
					return is_object($this->Shipping) ? true : false;
					break;
					
				case 'hasPayment':
					return is_object($this->Payment) ? true : false;
					break;
			}
		}
		
		return $this->arrCache[$strKey];
	}
	
	
	/**
	 * Load current cart
	 *
	 * @todo why do we need store_id in tl_cart?
	 */
	public function __construct()
	{
		$this->import('IsotopeStore', 'Store');
		
		parent::__construct();
		
		$this->strHash = $this->Input->cookie($this->strCookie);
		
		//  Check to see if the user is logged in.  If not, cart data should be found in session data.
		if (!FE_USER_LOGGED_IN)
		{	
			if(!strlen($this->strHash))	
			{	
				$this->strHash = sha1(session_id() . (!$GLOBALS['TL_CONFIG']['disableIpCheck'] ? $this->Environment->ip : '') . $this->Store->id . $this->strCookie);
				
				$this->setCookie($this->strCookie, $this->strHash, $this->Store->cookie_timeout,  $GLOBALS['TL_CONFIG']['websitePath']);
			}

			$this->findBy('session', $this->strHash);
		}
		else
		{
			$this->import('FrontendUser', 'User');
			
	 		$this->findBy('pid', $this->User->id);
		}
		
		// Create new cart
		if (!$this->blnRecordExists)
		{
			$this->setData(array
			(
				'pid'			=> (FE_USER_LOGGED_IN ? $this->User->id : 0),
				'session'		=> $this->strHash,
				'tstamp'		=> time(),
				'last_visit'	=> time(),
				'cart_type_id'	=> $this->intType,
				'store_id'		=> $this->Store->id,
			));
			
			if (!$this->findBy('id', $this->save(true)))
			{
				throw new Exception('Unable to create shopping cart');
			}
		}
		
		
		// Temporary cart available, move to this cart. Must be after creating a new cart!
 		if (FE_USER_LOGGED_IN && strlen($this->strHash))
 		{
 			$objCartData = $this->Database->prepare("SELECT ci.* FROM tl_cart c INNER JOIN tl_cart_items ci ON c.id=ci.pid WHERE c.session=? AND c.cart_type_id=?")->execute($this->strHash, $this->intType);
										  
			while( $objCartData->next() )
			{
				$blnExists = $this->Database->prepare("SELECT COUNT(*) as count FROM tl_cart_items WHERE product_id=? AND pid=? AND attribute_set_id=?")
											->limit(1)
											->execute($objCartData->product_id, $this->id, $objCartData->attribute_set_id);
											 
				// Cart item exists, sum quantity
				if($blnExists)
				{
					$this->Database->prepare("UPDATE tl_cart_items SET quantity_requested=(quantity_requested+" . $objCartData->quantity_requested . ") WHERE product_id=? AND attribute_set_id=? AND pid=?")
								   ->execute($objCartData->product_id, $objCartData->attribute_set_id, $this->id);
									   
					$this->Database->prepare("DELETE FROM tl_cart_items WHERE id=?")->execute($objCartData->id);
				}
				
				// Simply move item to this cart
				else
				{
					$this->Database->prepare("UPDATE tl_cart_items SET pid=? WHERE id=?")->execute($this->id, $objCartData->id);
				}
			}
			
			// Delete cookie
			$this->setCookie($this->strCookie, '', (time() - 3600), $GLOBALS['TL_CONFIG']['websitePath']);
			
			// Delete cart
			$this->Database->prepare("DELETE FROM tl_cart WHERE session=?")->execute($this->strHash);
 		}
 		
 		// Load shipping object
 		if ($_SESSION['FORM_DATA']['shipping']['module'])
 		{
 			$objShipping = $this->Database->prepare("SELECT * FROM tl_shipping_modules WHERE id=?")->limit(1)->execute($_SESSION['FORM_DATA']['shipping']['module']);
 			if ($objShipping->numRows)
 			{
 				$strClass = $GLOBALS['ISO_SHIP'][$objShipping->type];
 				$this->Shipping = new $strClass($objShipping->row());
 			}
 		}
 		
 		
 		// Load payment object
 		if ($_SESSION['FORM_DATA']['payment']['module'])
 		{
 			$objPayment = $this->Database->prepare("SELECT * FROM tl_payment_modules WHERE id=?")->limit(1)->execute($_SESSION['FORM_DATA']['payment']['module']);
 			if ($objPayment->numRows)
 			{
 				$strClass = $GLOBALS['ISO_PAY'][$objPayment->type];
 				$this->Payment = new $strClass($objPayment->row());
 			}
 		}
	}
	
	
	/**
	 * Auto-Save to database
	 *//*
	public function __destruct()
	{
		// Update timestamp
		$this->tstamp = time();
		$this->last_visit = time();
		
		$this->save();
	}*/
	
	
	/**
	 * Find a record by its reference field and return true if it has been found. Include cart type id.
	 * @param  int
	 * @return boolean
	 */
	public function findBy($strRefField, $varRefId)
	{
		$this->blnRecordExists = false;
		$this->strRefField = $strRefField;
		$this->varRefId = $varRefId;

		$resResult = $this->Database->prepare("SELECT * FROM " . $this->strTable . " WHERE " . $this->strRefField . "=? AND cart_type_id=?")
									->execute($this->varRefId, $this->intType);

		if ($resResult->numRows == 1)
		{
			$this->arrData = $resResult->fetchAssoc();
			$this->blnRecordExists = true;

			return true;
		}

		return false;
	}
	

	/**
	 * Fetch products from database.
	 * 
	 * @access public
	 * @return array
	 */
	public function getProducts()
	{
		if (!$this->arrProducts)
		{
			$objCartData = $this->Database->prepare("SELECT tl_cart_items.*, tl_product_attribute_sets.storeTable FROM tl_cart_items LEFT OUTER JOIN tl_product_attribute_sets ON tl_cart_items.attribute_set_id=tl_product_attribute_sets.id WHERE tl_cart_items.pid=?")->execute($this->id);
			
			$this->arrProducts = $objCartData->fetchAllAssoc();
		}
		
		return $this->arrProducts;
	}
	
	
	public function getProductsAsHtml()
	{
		$this->import('Isotope');
		
		$arrProducts = $this->Isotope->getProductData($this->getProducts(), array('product_alias','product_name','product_price'), 'product_name');
		
		if (!count($arrProducts))
			return '';
		
		$strBuffer  = "<table>\n";
		$strBuffer .= "<tr><td>Name</td><td>Anzahl</td><td>Preis</td><td>Betrag</td></tr>\n";
		
		foreach( $arrProducts as $product )
		{
			$strBuffer .= '<tr>';
			$strBuffer .= '<td>' . $product['product_name'] . '</td>';
			$strBuffer .= '<td>' . $product['quantity_requested'] . ' x </td>';
			$strBuffer .= '<td>' . $this->Isotope->formatPriceWithCurrency($product['product_price']) . '</td>';
			$strBuffer .= '<td>' . $this->Isotope->formatPriceWithCurrency($product['quantity_requested'] * $product['product_price']) . '</td>';
			$strBuffer .= "</tr>\n";
		}
		
		return $strBuffer . '</table>';
	}
	
	
	public function getProductsAsString()
	{
		$this->import('Isotope');
		
		$arrProducts = $this->Isotope->getProductData($this->getProducts(), array('product_alias','product_name','product_price'), 'product_name');
		
		if (!count($arrProducts))
			return 'Keine Produkte';
		
//		$strBuffer = "Name    Anzahl</td><td>Preis</td><td>Betrag</td></tr>\n";
		
		foreach( $arrProducts as $product )
		{
			$strBuffer .= $product['product_name'] . ': ';
			$strBuffer .= $product['quantity_requested'] . ' x ';
			$strBuffer .= $this->Isotope->formatPriceWithCurrency($product['product_price']) . ' = ';
			$strBuffer .= $this->Isotope->formatPriceWithCurrency($product['quantity_requested'] * $product['product_price']);
		}
		
		return $strBuffer;
	}
	

	/**
	 * Calculate total price for products.
	 * 
	 * @access protected
	 * @param array $arrProductData
	 * @return float
	 */
	protected function calculateTotal($arrProductData)
	{
		$fltTotal = 0;
		
		foreach($arrProductData as $data)
		{
			$fltTotal += ((float)$data['product_price'] * (int)$data['quantity_requested']);
		}
		
		$taxPriceAdjustment = 0; // $this->getTax($floatSubTotalPrice, $arrTaxRules, 'MULTIPLY');
		
		return (float)$fltTotal + (float)$taxPriceAdjustment;
	}

	
	
	
	/**
	 * Check if a product is already in cart.
	 * 
	 * @todo use cache data
	 * @access public
	 * @param int $intProductId
	 * @param int $intAttributeSetId
	 * @return bool
	 */
	public function containsProduct($intProductId, $intAttributeSetId)
	{
		return ($this->Database->prepare("SELECT * FROM tl_cart_items WHERE pid=? AND product_id=? AND attribute_set_id=?")
							   ->limit(1)
							   ->execute($this->id, $intProductId, $intAttributeSetId)
							   ->numRows ? true : false);
	}
}

