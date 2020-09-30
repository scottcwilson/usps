<?php
/**
 * USPS Module for Zen Cart v1.3.x thru v1.6
 * USPS RateV4 Intl RateV2 - March 28, 2018 Version K10

 * Prices from: Sept 16, 2017
 * Rates Names: Sept 16, 2017
 * Services Names: Sept 16, 2017
 *
 * @package shippingMethod
 * @copyright Copyright 2003-2016 Zen Cart Development Team

 * @copyright Portions Copyright 2003 osCommerce
 * @copyright Portions adapted from 2012 osCbyJetta
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: usps.php 2017-09-16 ajeh - tflmike, 2018-03-28 - bislewl  Version K10 $
 */
if (!defined('IS_ADMIN_FLAG')) {
    exit('Illegal Access');
}
/**
 * USPS Shipping Module class
 *
 */
class usps extends base 
{
  /**
   * Declare shipping module alias code
   *
   * @var string
   */
  public $code,
  /**
   * Shipping module display name
   *
   * @var string
   */
        $title,
  /**
   * Shipping module display description
   *
   * @var string
   */
        $description,
  /**
   * Shipping module icon filename/path
   *
   * @var string
   */
        $icon,
  /**
   * Shipping module status
   *
   * @var boolean
   */
        $enabled,
  /**
   * Shipping module list of supported countries
   *
   * @var array
   */
        $countries,
  /**
   *  use USPS translations for US shops
   *  @var string
   */
        $usps_countries,
  /**
   * List of services checkboxes, extracted out into an array
   * @var array
   */
        $typeCheckboxesSelected = array(),
  /**
   * USPS certain methods don't qualify if declared value is greater than $400
   * @var array
   */
        $typeCheckboxesSelected_to_skip_over_certain_value = array(),
  /**
   * use for debug log of what is sent to usps
   * @var string
   */
        $request_display;
    
    // -----
    // Class constant to define the current module version.
    //
    const USPS_CURRENT_VERSION = '2018-03-28 K10';
    
  /**
   * Constructor
   *
   * @return object
   */

    public function __construct() 
    {
        global $db, $template, $current_page_base, $current_page;

        $this->code = 'usps';
        $this->title = (MODULE_SHIPPING_USPS_TITLE_SIZE == 'Short' ? MODULE_SHIPPING_USPS_TEXT_SHORT_TITLE : MODULE_SHIPPING_USPS_TEXT_TITLE);
        $this->description = MODULE_SHIPPING_USPS_TEXT_DESCRIPTION;
        $this->sort_order = (defined('MODULE_SHIPPING_USPS_SORT_ORDER')) ? MODULE_SHIPPING_USPS_SORT_ORDER : null;
        if ($this->sort_order === null) {
            return false;
        }
        
        $this->enabled = (MODULE_SHIPPING_USPS_STATUS == 'True');
        
        // -----
        // During admin processing (Modules :: Shipping), let the admin know of some test
        // conditions.  Limiting to that script so that the additions don't show up during
        // Edit Orders (and possibly others).
        //
        if ($this->enabled && IS_ADMIN_FLAG && $current_page == 'modules.php') {
            if (MODULE_SHIPPING_USPS_DEBUG_MODE != 'Off') {
                $this->title .=  '<span class="alert"> (Debug is ON: ' . MODULE_SHIPPING_USPS_DEBUG_MODE . ')</span>';
            }
            if (MODULE_SHIPPING_USPS_SERVER != 'production') {
                $this->title .=  '<span class="alert"> (USPS Server set to: ' . MODULE_SHIPPING_USPS_SERVER . ')</span>';
            }
        }

        $this->tax_class = (int)MODULE_SHIPPING_USPS_TAX_CLASS;
        $this->tax_basis = MODULE_SHIPPING_USPS_TAX_BASIS;

        $this->typeCheckboxesSelected = explode(', ', MODULE_SHIPPING_USPS_TYPES);
        $this->update_status();

        // check if all keys are in configuration table and correct version
        if ($this->enabled && IS_ADMIN_FLAG) {
            $chk_sql = $db->Execute(
                "SELECT * 
                   FROM " . TABLE_CONFIGURATION . " 
                  WHERE configuration_key like 'MODULE\_SHIPPING\_USPS\_%' ");
            if (MODULE_SHIPPING_USPS_VERSION != self::USPS_CURRENT_VERSION || count($this->keys()) != $chk_sql->RecordCount()) {
                $this->title .= '<span class="alert">' . ' - Missing Keys or Out of date you should reinstall!' . '</span>';
                $this->enabled = false;
            }
            $new_version_details = plugin_version_check_for_updates(1292, self::USPS_CURRENT_VERSION);
            if ($new_version_details !== false) {
                $this->title .= '<span class="alert">' . ' - NOTE: A NEW VERSION OF THIS PLUGIN IS AVAILABLE. <a href="' . $new_version_details['link'] . '" target="_blank">[Details]</a>' . '</span>';
            }

            if ($this->enabled) {
                // insert checks here to give warnings if some of the configured selections don't make sense (such as no boxes checked)
                // And in those cases, set $this->enabled to FALSE so that the amber warning symbol appears. Consider also adding more BRIEF error text to the $this->title.

                // verify checked boxes
                $usps_shipping_methods_domestic_cnt = 0;
                $usps_shipping_methods_international_cnt = 0;
                foreach($this->typeCheckboxesSelected as $requested_type) {
                    if (is_numeric($requested_type) || preg_match('#(GXG|International)#i', $requested_type)) {
                        continue;
                    }
                    $usps_shipping_methods_domestic_cnt++;
                }
                foreach ($this->typeCheckboxesSelected as $requested_type) {
                    if (is_numeric($requested_type) || !preg_match('#(GXG|International)#i' , $requested_type)) {
                        continue;
                    }
                    $usps_shipping_methods_international_cnt++;
                }
                if (($usps_shipping_methods_domestic_cnt + $usps_shipping_methods_international_cnt) < 1) {
                    $this->title .= '<span class="alert">' . ' - Nothing has been selected for Quotes.' . '</span>';
                }
            }
        }

        if (SHIPPING_ORIGIN_COUNTRY != '223') {
            $this->title .= '<span class="alert">' . ' - USPS can only ship from USA, but your store is configured with another origin! See Admin->Configuration->Shipping/Packaging.' . '</span>';
        }

        if (isset($template)) {
            $this->icon = $template->get_template_dir('shipping_usps.gif', DIR_WS_TEMPLATE, $current_page_base, 'images/icons') . '/shipping_usps.gif';
        }

        // -----
        // Some processing is admin/storefront conditional, since there's no $order element
        // available during the admin's Modules->Shipping processing.
        //
        if (IS_ADMIN_FLAG === false) {
            // prepare list of countries which USPS ships to
            $this->countries = $this->country_list();

            // use USPS translations for US shops (USPS treats certain regions as "US States" instead of as different "countries", so we translate here)
            $this->usps_countries = $this->usps_translation();

            // certain methods don't qualify if declared value is greater than $400
            $this->types_to_skip_over_certain_value = array(
               'Priority Mail InternationalRM Flat Rate Envelope' => 400,
               'Priority Mail InternationalRM Small Flat Rate Envelope' => 400,
               'Priority Mail InternationalRM Small Flat Rate Box' => 400,
               'Priority Mail InternationalRM Legal Flat Rate Envelope' => 400,
               'Priority Mail InternationalRM Padded Flat Rate Envelope' => 400,
               'Priority Mail InternationalRM Gift Card Flat Rate Envelope' => 400,
               'Priority Mail InternationalRM Window Flat Rate Envelope' => 400,
               'First-Class MailRM International Letter' => 400,
               'First-Class MailRM International Large Envelope' => 400,
               'First-Class Package International ServiceTM' => 400,
            );

            $this->getTransitTime = (in_array('Display transit time', explode(', ', MODULE_SHIPPING_USPS_OPTIONS)));

            $this->shipping_cutoff_time = '1400'; // 1400 = 14:00 = 2pm ---- must be HHMM without punctuation
        }

        $this->notify('NOTIFY_SHIPPING_USPS_CONSTRUCTOR_COMPLETED');
    }

    /**
      * check whether this module should be enabled or disabled based on zone assignments and any other rules
    */
    public function update_status() 
    {
        global $order, $db;
        if (IS_ADMIN_FLAG === true) {
            return;
        }

        // disable only when entire cart is free shipping
        if (!zen_get_shipping_enabled($this->code)) {
            $this->enabled = false;
        }

        if ($this->enabled == true && (int)MODULE_SHIPPING_USPS_ZONE > 0) {
            $check_flag = false;
            $check = $db->Execute(
                "SELECT zone_id 
                   FROM " . TABLE_ZONES_TO_GEO_ZONES . " 
                  WHERE geo_zone_id = " . (int)MODULE_SHIPPING_USPS_ZONE . " 
                    AND zone_country_id = " . (int)$order->delivery['country']['id'] . " 
                  ORDER BY zone_id ASC"
            );
            while (!$check->EOF) {
                if ($check->fields['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                } elseif ($check->fields['zone_id'] == $order->delivery['zone_id']) {
                    $check_flag = true;
                    break;
                }
                $check->MoveNext();
            }

            if ($check_flag == false) {
                $this->enabled = false;
            }
        }

        global $template, $current_page_base;
        // CUSTOMIZED CONDITIONS GO HERE
        // Optionally add additional code here to change $this->enabled to false based on whatever custom rules you require.
        // -----

        // -----
        // eof: optional additional code

        $this->notify('NOTIFY_SHIPPING_USPS_UPDATE_STATUS');
    }

    /**
     * Prepare request for quotes and process obtained results
     *
     * @param string $method
     * @return array of quotation results
     */
    public function quote($method = '')
    {
        global $order, $shipping_weight, $shipping_num_boxes, $currencies, $shipping;

        $usps_shipping_quotes = '';
        $iInfo = '';
        $methods = array();
        $usps_shipping_weight = ($shipping_weight < 0.0625) ? 0.0625 : $shipping_weight;
        $this->pounds = (int)$usps_shipping_weight;
        
        // usps currently cannot handle more than 5 digits on international
        // change to 2 if International rates fail based on Tare Settings
        $this->ounces = ceil(round(16 * ($usps_shipping_weight - $this->pounds), MODULE_SHIPPING_USPS_DECIMALS));

        // Determine machinable or not
        // weight must be less than 35lbs and greater than 6 ounces or it is not machinable
        switch (true) {
            // force machinable for $0.49 remove the false && from the first case
            case (false && ($this->is_us_shipment && ($this->pounds == 0 && $this->ounces <= 1))):
                // override admin choice, too light
                $this->machinable = 'True';
                break;

            case ($this->is_us_shipment && ($this->pounds == 0 && $this->ounces < 6)):
                // override admin choice, too light
                $this->machinable = 'False';
                break;

            case (!$this->is_us_shipment && ($this->pounds == 0 && $this->ounces < 3.5)):
                // override admin choice, too light
                $this->machinable = 'False';
                break;

            case ($usps_shipping_weight > 35):
                // override admin choice, too heavy
                $this->machinable = 'False';
                break;

            default:
                // admin choice on what to use
                $this->machinable = MODULE_SHIPPING_USPS_MACHINABLE;
                break;
        }

        // What method to use for calculating display of transit times
        // Options: 'NEW' = <ShipDate>, 'OLD' = extra API calls, 'CUSTOM' = hard-coded elsewhere in the parseDomesticTransitTimeResults() function.
        $this->transitTimeCalculationMode = MODULE_SHIPPING_USPS_TRANSIT_TIME_CALCULATION_MODE;
        // NOTE: at the present time, with the Test/Staging server, using the new method of adding shipdate adds a lot more time to obtaining quotes

//echo 'USPS Info:' . '<br>$usps_shipping_weight: ' . $usps_shipping_weight . '<br>$this->pounds: ' . $this->pounds . ' $this->ounces: ' . $this->ounces . '<br>$this->machinable: ' . $this->machinable . '<br>US First Class Filter: ' . MODULE_SHIPPING_USPS_FIRST_CLASS_FILTER_US . '<br>$this->usps_countries: ' . $this->usps_countries . '<br>';

        // request quotes
        $this->notify('NOTIFY_SHIPPING_USPS_BEFORE_GETQUOTE', array(), $order, $usps_shipping_weight, $shipping_num_boxes);
        $this->request_display = '';
        $this->uspsQuote = $this->_getQuote();
//echo 'USPS function quote TRUE $this->uspsQuote $this->enabled: ' . ($this->enabled ? ' ON' : ' OFF') . ' $shipping_weight: ' . $shipping_weight . '<br>';
        $this->notify('NOTIFY_SHIPPING_USPS_AFTER_GETQUOTE', array(), $order, $usps_shipping_weight, $shipping_num_boxes);
        $uspsQuote = $this->uspsQuote;
//echo 'USPS $uspsQuote: ' . '<pre>'; echo var_dump($uspsQuote); echo '</pre>' . '<br>';

        // were errors encountered?
        if ($uspsQuote === -1) {
            $this->quotes = array(
                'module' => $this->title,
                'error' => MODULE_SHIPPING_USPS_TEXT_SERVER_ERROR . (MODULE_SHIPPING_USPS_SERVER == 'test' ? MODULE_SHIPPING_USPS_TEXT_TEST_MODE_NOTICE : '')
            );
            return $this->quotes;
        }
        if (!is_array($uspsQuote)) {
            $this->quotes = array(
                'module' => $this->title,
                'error' => MODULE_SHIPPING_USPS_TEXT_ERROR . (MODULE_SHIPPING_USPS_SERVER == 'test' ? MODULE_SHIPPING_USPS_TEXT_TEST_MODE_NOTICE : '')
            );
            return $this->quotes;
        }
        if (isset($uspsQuote['Number']) && !isset($uspsQuote['error'])) {
            $uspsQuote['error'] = $uspsQuote['Number'] . ' - ' . $uspsQuote['Description'];
        }
        if (isset($uspsQuote['error'])) {
            if ($uspsQuote['Number'] == -2147219085) {
                $this->quotes = array(
                    'module' => $this->title,
                    'error' => 'NO OPTIONS INSTALLED: ' . $uspsQuote['error']
                );
            } else {
                $this->quotes = array(
                    'module' => $this->title,
                    'error' => $uspsQuote['error']
                );
            }
            return $this->quotes;
        }

        // if we got here, there were no errors, so proceed with evaluating the obtained quotes

        $services_domestic = 'Domestic Services Selected: ' . "\n";
        $services_international = 'International Services Selected: ' . "\n";

        // obtain list of selected services ... so we can evaluate returned quoted services against the services selected by the store administrator (since USPS returns more than we ask for)
        $servicesSelectedDomestic = $this->special_services();
        $servicesSelectedIntl = $this->extra_service();

        // Domestic/US destination:
        if ($this->is_us_shipment) {
            $dExtras = array(); // We're going to populate this with a list of "friendly names" of services "checked" to "Y" in our checkboxes
            $dOptions = explode(', ', MODULE_SHIPPING_USPS_DMST_SERVICES); // domestic
//echo 'line ' . (__LINE__) . ' USPS domestic $dOptions: ' . '<pre>'; echo var_dump($dOptions); echo '</pre>' . '<br>';
            foreach ($dOptions as $key => $val) {
                if (strlen($dOptions[$key]) > 1) {
//echo 'line ' . (__LINE__) . ' $dOptions[$key]: ' . $dOptions[$key] . '=' . $dOptions[$key+1]. '<br>';
                    if ($dOptions[$key+1] == 'C' || $dOptions[$key+1] == 'S' || $dOptions[$key+1] == 'Y') {
                        $services_domestic .= $dOptions[$key] . "\n";
                        $dExtras[$dOptions[$key]] = $dOptions[$key+1];
                    }
//echo '$dOptions[$key]: > 1 [' . $dOptions[$key] . '] $dOptions[$key+1]: ' . $dOptions[$key+1] . ' $dExtras[$dOptions[$key]]: ' . $dExtras[$dOptions[$key]] . '<br>';
                }
            }
//echo '<pre>$dExtras='.print_r($dExtras, true) . '<pre>';
        } else {
            // International destination:
            $iExtras = array();
            $iOptions = explode(', ', MODULE_SHIPPING_USPS_INTL_SERVICES);
//echo 'line ' . (__LINE__) . ' USPS international $iOptions: ' . '<pre>'; echo var_dump($iOptions); echo '</pre>' . '<br>';
            foreach ($iOptions as $key => $val) {
                if (strlen($iOptions[$key]) > 1) {
                    if ($iOptions[$key+1] == 'C' || $iOptions[$key+1] == 'S' || $iOptions[$key+1] == 'Y') {
                        $services_international .= $iOptions[$key] . "\n";
                        $iExtras[$iOptions[$key]] = $iOptions[$key+1];
                    }
//echo '$iOptions[$key]: > 1 ' . $iOptions[$key] . ' $iOptions[$key+1]: ' . $iOptions[$key+1] . ' $iExtras[$iOptions[$key]]: ' . $iExtras[$iOptions[$key]] . '<br>';
                }
            }
    
            if (MODULE_SHIPPING_USPS_REGULATIONS == 'True') {
                $iInfo = '<div id="iInfo">' . "\n" .
                   '  <div id="showInfo" class="ui-state-error" style="cursor:pointer; text-align:center;" onclick="$(\'#showInfo\').hide();$(\'#hideInfo, #Info\').show();">' . MODULE_SHIPPING_USPS_TEXT_INTL_SHOW . '</div>' . "\n" .
                   '  <div id="hideInfo" class="ui-state-error" style="cursor:pointer; text-align:center; display:none;" onclick="$(\'#hideInfo, #Info\').hide();$(\'#showInfo\').show();">' . MODULE_SHIPPING_USPS_TEXT_INTL_HIDE .'</div>' . "\n" .
                   '  <div id="Info" class="ui-state-highlight" style="display:none; padding:10px; max-height:200px; overflow:auto;">' . '<b>Prohibitions:</b><br />' . nl2br($uspsQuote['Package']['Prohibitions']) . '<br /><br /><b>Restrictions:</b><br />' . nl2br($uspsQuote['Package']['Restrictions']) . '<br /><br /><b>Observations:</b><br />' . nl2br($uspsQuote['Package']['Observations']) . '<br /><br /><b>CustomsForms:</b><br />' . nl2br($uspsQuote['Package']['CustomsForms']) . '<br /><br /><b>ExpressMail:</b><br />' . nl2br($uspsQuote['Package']['ExpressMail']) . '<br /><br /><b>AreasServed:</b><br />' . nl2br($uspsQuote['Package']['AreasServed']) . '<br /><br /><b>AdditionalRestrictions:</b><br />' . nl2br($uspsQuote['Package']['AdditionalRestrictions']) .'</div>' . "\n" .
                   '</div>';
            }
        }

        if ($this->is_us_shipment) {
            $PackageSize = count($uspsQuote['Package']);
            // if object has no legitimate children, turn it into a firstborn:
            if (isset($uspsQuote['Package']['ZipDestination']) && !isset($uspsQuote['Package'][0]['Postage'])) {
                $uspsQuote['Package'][] = $uspsQuote['Package'];
                $PackageSize = 1;
            }
        } else {
            $PackageSize = count($uspsQuote['Package']['Service']);
        }

        // display 1st occurance of First Class and skip others for the US - start counter
        $cnt_first = 0;

        // *** Customizations once per display ***

        // bof: example to block USPS Priority MailTM Small Flat Rate Box when anything from master_categories_id 12 or 15 are in the cart
        // change false to true to use
        if (false) {
            $chk_cart = 0;
            $chk_cart += $_SESSION['cart']->in_cart_check('master_categories_id','12');
            $chk_cart += $_SESSION['cart']->in_cart_check('master_categories_id','15');
        }
        // see below use of $chk_cart
// eof: example to block USPS Priority MailTM Small Flat Rate Box when anything from master_categories_id 12 or 15 are in the cart

        for ($i=0; $i < $PackageSize; $i++) {
            if (!empty($uspsQuote['Package'][$i]['Error'])) {
                continue;
            }
            $Services = array();
            $hiddenServices = array();
            $hiddenCost = 0;
            $handling = 0;
            $usps_insurance_charge = 0;

            $Package = ($this->is_us_shipment) ? $uspsQuote['Package'][$i]['Postage'] : $uspsQuote['Package']['Service'][$i];
//echo 'line ' . (__LINE__) . ' $Package checked' . '<br>';
//echo '<pre>'; echo var_dump($Package); echo '</pre>';

            // Domestic first
            if ($this->is_us_shipment) {
                if (!empty($Package['SpecialServices']['SpecialService'])) {
//echo 'USPS DOMESTIC $Package[SpecialServices][SpecialService]:<pre>'; echo var_dump($Package['SpecialServices']['SpecialService']); echo '</pre><br>';

                    // if object has no legitimate children, turn it into a firstborn:
                    if (isset($Package['SpecialServices']['SpecialService']['ServiceName']) && !isset($Package['SpecialServices']['SpecialService'][0])) {
                        $Package['SpecialServices']['SpecialService'] = array($Package['SpecialServices']['SpecialService']);
                    }

                    foreach ($Package['SpecialServices']['SpecialService'] as $key => $val) {
                        // translate friendly names for Insurance Restricted Delivery 177, 178, 179, since USPS rebranded to remove all sense of explanations
                        if ($val['ServiceName'] == 'Insurance Restricted Delivery') {
                            if ($val['ServiceID'] == 178) {
                                $val['ServiceName'] = 'Insurance Restricted Delivery (Priority Mail Express)';
                            }
                            if ($val['ServiceID'] == 179) {
                                $val['ServiceName'] = 'Insurance Restricted Delivery (Priority Mail)';
                            }
                        }
                        // translate friendly names for insurance 100, 101, 125, since USPS rebranded to remove all sense of explanations
                        if ($val['ServiceName'] == 'Insurance') {
                            if ($val['ServiceID'] == 125) {
                                $val['ServiceName'] = 'Priority Mail Insurance';
                            }
                            if ($val['ServiceID'] == 101) {
                                $val['ServiceName'] = 'Priority Mail Express Insurance';
                            }
                        }

//echo 'line ' . (__LINE__) . ' $val <pre>'; echo var_dump($val); echo '</pre>';
//echo 'Found ' . '$val[ServiceName]: ' . $val['ServiceName'] . ' vs ' . $this->clean_usps_marks($val['ServiceName']) . ' $dExtras[$val[ServiceName]]: ' . $this->clean_usps_marks($dExtras[$val['ServiceName']]) . '<br>';
                        $val['ServiceName'] = $this->clean_usps_marks($val['ServiceName']);
//echo '<br>USPS Domestic A: $dExtras[$val[ServiceName]]: [' . $val['ServiceName'] . '] $val: [' . print_r($val, true) . ']<br>';
                        if (isset($dExtras[$val['ServiceName']]) && zen_not_null($dExtras[$val['ServiceName']]) && ((MODULE_SHIPPING_USPS_RATE_TYPE == 'Online' && strtoupper($val['AvailableOnline']) == 'TRUE') || (MODULE_SHIPPING_USPS_RATE_TYPE == 'Retail' && strtoupper($val['Available']) == 'TRUE'))) {
//echo '<br>USPS Domestic B: $dExtras[$val[ServiceName]]: ' . $dExtras[$val['ServiceName']] . ' $val: ' . print_r($val) . '<br>';
                            $val['ServiceAdmin'] = $this->clean_usps_marks($dExtras[$val['ServiceName']]);
                            $Services[] = $val;
//echo 'line ' . (__LINE__) . ' $Services $val checked' . '<br>';
//echo '<pre>'; echo var_dump($val); echo '</pre>';
//echo 'USPS Domestic $d $key: ' . $key . ' $val[ServiceAdmin]:' . $val['ServiceAdmin'] . ' $val: ' . $val . ' $val[ServiceName]: ' . $val['ServiceName'] . '<br>';
                        }
//echo 'USPS Domestic $d $key: ' . $key . ' $val[ServiceAdmin]:' . $val['ServiceAdmin'] . ' $val: ' . $val . ' $val[ServiceName]: ' . $val['ServiceName'] . '<br>';
                    }
                }

                $cost = MODULE_SHIPPING_USPS_RATE_TYPE == 'Online' && zen_not_null($Package['CommercialRate']) ? $Package['CommercialRate'] : $Package['Rate'];
                $type = $this->clean_usps_marks($Package['MailService']);
                // methods shipping zone
                $usps_shipping_methods_zone = $uspsQuote['Package'][$i]['Zone'];
            } else {
          // International
//echo 'USPS INTERNATIONAL $Package[ExtraServices][ExtraService]: <pre>'; echo var_dump($Package['ExtraServices']['ExtraService'], true); echo '</pre><br>';
//echo '<pre>'; echo var_dump($Package); echo '</pre>';
//echo '<pre>'; echo var_dump($iExtras); echo '</pre>';
//          if (isset($Package['ExtraServices']) && isset($Package['ExtraServices']['ExtraService'])) foreach ($Package['ExtraServices']['ExtraService'] as $key => $val) {
//echo '<pre>'; echo var_dump($Package); echo '</pre>';
//echo '<pre>'; echo print_r($Package['ExtraServices']['ExtraService']); echo '</pre>';
                if (is_array($Package['ExtraServices']['ExtraService'])) {

                    // if object has no legitimate children, turn it into a firstborn:
                    if (isset($Package['ExtraServices']['ExtraService']['ServiceName']) && !isset($Package['ExtraServices']['ExtraService'][0])) {
                        $Package['ExtraServices']['ExtraService'] = array($Package['ExtraServices']['ExtraService']);
                    }

                    foreach ($Package['ExtraServices']['ExtraService'] as $key => $val) {
//echo '<pre>'; echo var_dump($val); echo '</pre>';
//echo '<pre>'; echo var_dump($Package); echo '</pre>';
//echo 'Found:' . ' $Package[SvcDescription]: ' . $Package['SvcDescription'] . ' $val[ServiceName]: ' . $val['ServiceName'] . ' $iExtras[$val[ServiceName]]: ' . $iExtras[$val['ServiceName']] . ' $val[Price]: ' . $val['Price'] . ' $val[PriceOnline]: ' . $val['PriceOnline'] . ' $val[Available]: ' . $val['Available'] . ' $val[OnlineAvailable]: ' . $val['OnlineAvailable'] . '<br>';
                        $val['ServiceName'] = $this->clean_usps_marks($val['ServiceName']);
//echo 'USPS International A: $i $key: ' . $key . ' $val[ServiceName]: ' . $val['ServiceName'] . ' $val: ' . $val . '<br>';
//              if (isset($val['ServiceName']) && isset($iExtras[$val['ServiceName']]) && zen_not_null($iExtras[$val['ServiceName']]) && ((MODULE_SHIPPING_USPS_RATE_TYPE == 'Online' && $val['OnlineAvailable'] == 'True') || (MODULE_SHIPPING_USPS_RATE_TYPE == 'Retail' && $val['Available'] == 'True'))) {
                        if (isset($iExtras[$val['ServiceName']]) && zen_not_null($iExtras[$val['ServiceName']]) && ((MODULE_SHIPPING_USPS_RATE_TYPE == 'Online' && strtoupper($val['OnlineAvailable']) == 'TRUE') || (MODULE_SHIPPING_USPS_RATE_TYPE == 'Retail' && strtoupper($val['Available']) == 'TRUE'))) {
                            $val['ServiceAdmin'] = $this->clean_usps_marks($iExtras[$val['ServiceName']]);
                            $Services[] = $val;
//echo 'USPS International B: $i $key: ' . $key . ' $val[ServiceAdmin]: ' . $val['ServiceAdmin'] . ' $val: ' . $val . '<br>';
                        }
//echo '<br>';
                    }
                }
//echo 'USPS International $i $key: ' . $key . ' $val[ServiceAdmin]: ' . $val['ServiceAdmin'] . ' $val: ' . $val . '<br>';
                $cost = MODULE_SHIPPING_USPS_RATE_TYPE == 'Online' && zen_not_null($Package['CommercialPostage']) ? $Package['CommercialPostage'] : $Package['Postage'];
                $type = $this->clean_usps_marks($Package['SvcDescription']);
            }
            if ($cost == 0) {
                continue;
            }

            // This is used for overriding the matching needed because USPS is inconsistent in how they return the names

//  // simulate appending the RM
//  if (preg_match('#First#', $type)) $type .="RM";

            $type_rebuilt = $type;

            // bof: example to block USPS Priority MailTM Small Flat Rate Box when anything from master_categories_id 12 or 15 are in the cart
            // see above for $chk_cart settings
            // change false to true to use
            if (false) {
                if ($chk_cart > 0 && $type == 'Priority MailTM Small Flat Rate Box') {
            //  echo 'USPS $type: ' . $type . ' $chk_cart:' . $chk_cart . '<br>';
                    continue;
                }
            }
            // eof: example to block USPS Priority MailTM Small Flat Rate Box when anything from master_categories_id 12 or 15 are in the cart

            // Detect which First-Class type has been quoted, since USPS doesn't consistently return the type in the name of the service
            if (!isset($Package['FirstClassMailType']) || $Package['FirstClassMailType'] == '') {
                if (isset($uspsQuote["Package"][$i]) && isset($uspsQuote["Package"][$i]['FirstClassMailType']) && $uspsQuote["Package"][$i]['FirstClassMailType'] != '') {
                    $Package['FirstClassMailType'] = $uspsQuote["Package"][$i]['FirstClassMailType']; // LETTER or FLAT or PARCEL
                }
            }

            // init vars used later
            $minweight = $maxweight = $handling = 0;

            // Build a match pattern for regex compare later against selected allowed services
            $Package['lookupRegex'] = preg_quote($type) . '(?:RM|TM)?$';
            if (isset($Package['FirstClassMailType'])) {
                if (strcasecmp($Package['FirstClassMailType'], 'LETTER') === 0) {
                    $Package['lookupRegex'] = preg_replace('#Mail(?:RM|TM)?#', 'Mail(?:RM|TM)?(?: Stamped )?.*', preg_quote($type)) . (!$this->is_us_shipment ? '(GXG|International)?.*' : '') . $Package['FirstClassMailType'];
                } elseif (strcasecmp($Package['FirstClassMailType'], 'PARCEL') === 0) {
                     $Package['lookupRegex'] = preg_replace('#Mail(?:RM|TM)?#', 'Mail.*', preg_quote($type)) . (!$this->is_us_shipment ? '(GXG|International)?.*' : '') . $Package['FirstClassMailType'];
                } elseif (strcasecmp($Package['FirstClassMailType'], 'FLAT') === 0) {
                    $Package['lookupRegex'] = preg_replace('#Mail(?:RM|TM)?#', 'Mail.*', preg_quote($type)) . (!$this->is_us_shipment ? '(GXG|International)?.*' : '') . 'Envelope';
                }
            }
            $Package['lookupRegex'] = str_replace('Stamped Letter', 'Letter', $Package['lookupRegex']);
            $Package['lookupRegex'] = str_replace('LetterLETTER', 'Letter', $Package['lookupRegex']);
            $Package['lookupRegex'] = str_replace('ParcelEnvelope', 'Envelope', $Package['lookupRegex']);
            $Package['lookupRegex'] = str_replace('EnvelopeEnvelope', 'Envelope', $Package['lookupRegex']);
            $Package['lookupRegex'] = str_replace('ParcelPARCEL', 'Parcel', $Package['lookupRegex']);
// echo 'USPS B 492 $Package[FirstClassMailType]: ' . $Package['FirstClassMailType'] . ' $type: ' . $type . ' $Package[lookupRegex]: ' . $Package['lookupRegex'] . '<br><br>';
//echo '<br>after Line ' . (__LINE__ -1) . ' $method: ' . $method . ' -- $type: ' . $type . ' -- RegEx: /' . $Package['lookupRegex'] . '/ -- weight: ' . $usps_shipping_weight . ' --min: ' . $minweight . ' --max: ' . $maxweight . '<br>';

            // Certain methods cannot ship if declared value is over $400, so we "continue" which skips the current $type and proceeds with the next one in the loop:
            if (isset($this->types_to_skip_over_certain_value[$type]) && $_SESSION['cart']->total > $this->types_to_skip_over_certain_value[$type]) {
//echo 'Skipping: ' . $type . ' because amount is over ' . $this->types_to_skip_over_certain_value[$type] . '<br>';
                continue;
            }

//echo 'USPS $type: ' . $type . ' $usps_shipping_methods_zone: ' . $usps_shipping_methods_zone . '<br>';

//echo "\n<br>------ Testing USPS response \$type: <strong>$type $Package[FirstClassMailType] </strong> against checkboxes---------<br>\n";
            // process weight/handling settings from admin checkboxes
            foreach ($this->typeCheckboxesSelected as $key => $val) {
                if (is_numeric($val) || $val == '') {
                    continue;
                }

// echo '<br>before Line ' . (__LINE__ + 1) . ' $method: ' . $method . ' -- $val: ' . $val . ' -- RegEx: /' . $Package['lookupRegex'] . '/ -- weight: ' . $usps_shipping_weight . ' --min: ' . $minweight . ' --max: ' . $maxweight . '<br>';
                if ($val == $type || preg_match('#' . $Package['lookupRegex'] . '#i', $val) ) {
                    if (strpos($val, 'International') && !strpos($type, 'International')) {
                        continue;
                    }
                    if (strpos($val, 'GXG') && !strpos($type, 'GXG')) {
                        continue;
                    }
// echo "\n\$val: <strong>" . $val . '</strong> -- $type: ' . $type . ($Package['FirstClassMailType'] != '' ? ' -- FCMailType: <strong>' . $Package['FirstClassMailType'] . '</strong>' : '') . ' -- RegEx to Match: <strong>' . $Package['lookupRegex'] . "</strong>\n<br>";
                    $minweight = $this->typeCheckboxesSelected[$key+1];
                    $maxweight = $this->typeCheckboxesSelected[$key+2];
                    $handling = $this->typeCheckboxesSelected[$key+3];
//echo 'USPS MIN_MAX_HANDLING $type: ' . $type . ' $val: ' . $val . ' $minweight: ' . $minweight . ' $maxweight: ' . $maxweight . ' $Package[FirstClassMailType]: ' . $Package['FirstClassMailType'] . '<br><br>';
                    if ($val != $type && preg_match('#' . $Package['lookupRegex'] . '#i', $val) ) {
                        $type_rebuilt = $val;
                    }
                    break;
                }
//echo $this->typeCheckboxesSelected[$key] . ' min: ' . $this->typeCheckboxesSelected[$key+1] . ' max: ' . $this->typeCheckboxesSelected[$key+2] . ' hand: ' . $this->typeCheckboxesSelected[$key+3] . '<br>';
//echo 'USPS MODULE_SHIPPING_USPS_TYPES: <pre>'; echo print_r($this->typeCheckboxesSelected); echo '</pre>';

//echo 'USPS $val $type: ' . $type . ' $val: ' . $val . ' $minweight: ' . $minweight . ' $maxweight: ' . $maxweight . ' $cost: ' . $cost . '<br><br>';
            }

// echo '$type_rebuilt: ' . $type_rebuilt . '<br><br>';
            // process fees for additional services selected
//echo 'line ' . (__LINE__) . ' $Services checked' . '<br>';
//echo '<pre>'; echo var_dump($Services); echo '</pre>';
            foreach ($Services as $key => $val) {
//echo '<BR>$sDisplay build USPS $Services[$key][ServiceAdmin]: ' . $Services[$key]['ServiceAdmin'] . ' $val var dump: ' . var_dump($val) . '<br>';
                $sDisplay = $Services[$key]['ServiceAdmin'];
                if ($sDisplay == 'Y') {
                    $hiddenServices[] = array(
                        $Services[$key]['ServiceName'] . ' [' . $Services[$key]['ServiceID'] . ']' => (MODULE_SHIPPING_USPS_RATE_TYPE == 'Online' ? $Services[$key]['PriceOnline'] : $Services[$key]['Price'])
                    );
                }
            }
            // prepare costs associated with selected additional services
//echo '<pre>'; echo var_dump($Services[$key]['ServiceName']); echo '</pre>';
//echo 'line ' . (__LINE__) . ' $hiddenServices checked' . '<br>';
//echo '<pre>'; echo var_dump($hiddenServices); echo '$type='.$type;  echo '</pre>';
            $hidden_costs_breakdown = '';
            foreach($hiddenServices as $key => $val) {
                foreach($hiddenServices[$key] as $key1 => $val1) {
                    // add the cost to the accumulator
                    $hiddenCost += $val1;

                    // now check for insurance-specific codes, in order to augment the insurance counter

                    // extract the ServiceID, so we can test for specific insurance types
                    preg_match('/\[([0-9]{1,3})\]/', $key1, $matches);
                    $serviceID = $matches[1];
//echo '$type_rebuilt: ' . $type_rebuilt . ' ServiceID='. $serviceID . ' $key1: ' . $key1 . ' $matches[1]: ' . $matches[1] . ' $hiddenCost: ' . $hiddenCost . ' $val1: ' . $val1 . '<br>';
                    $hidden_costs_breakdown .= ($this->is_us_shipment ? ' SpecialServices: ' : ' ExtraServices: ') . $key1 . ' Amount: ' . number_format($val1, 2) . "\n";
                    // Test for Insurance type being returned  100=(General) Insurance, 125=Priority Mail, 101=Priority Mail Express

// Domestic Insurance 100, 101, 125 International 1
                    $insurance_test_flag = false;
                    if (preg_match('#Insurance#i', $key1)) {
                        // Domestic
                        if ($order->delivery['country']['id'] == SHIPPING_ORIGIN_COUNTRY || $this->is_us_shipment) {
                            if (strstr($servicesSelectedDomestic, $serviceID)) {
                                if (strstr($type, 'Priority Mail')) {
                                    if (strstr($type, 'Express')) {
                                        if ($serviceID == 101) {
                                            $insurance_test_flag = true;
                                        }
                                    } else {
                                        if ($serviceID == 125) {
                                            $insurance_test_flag = true;
                                        }
                                    }
                            } else {
                                if ($serviceID == 100) {
                                    $insurance_test_flag = true;
                                }
                            }
                        }
                        } else { // international
                            if ($serviceID == 1 && strstr($servicesSelectedIntl, $serviceID)) {
                                $insurance_test_flag = true;
                            }
                        }
                        if ($insurance_test_flag) {
//echo ' INSURANCE FLAG IS TRUE ... VAL1='.$val1;
                            $usps_insurance_charge = $val1;
                        }
                    }

//echo '<br>USPS Hidden Costs FOUND $type $hiddenCost: ' . $type . ' $key1: ' . $key1 . ' $val1: ' . $val1 . ' $hiddenCost: ' . $hiddenCost . ' $usps_insurance_charge: ' . $usps_insurance_charge . '<br>';
                }
//echo 'USPS Hidden Costs $type: ' . $type . ' $key1: ' . $key1 . ' $val1: ' . $val1 . ' $hiddenCost: ' . $hiddenCost . ' $usps_insurance_charge: ' . $usps_insurance_charge . '<br><br>';
            }
//echo '<br>';

            // set module-specific handling fee
            if ($order->delivery['country']['id'] == SHIPPING_ORIGIN_COUNTRY || $this->is_us_shipment) {
                // domestic/national
                $usps_handling_fee = MODULE_SHIPPING_USPS_HANDLING;
            } else {
                // international
                $usps_handling_fee = MODULE_SHIPPING_USPS_HANDLING_INT;
            }

            // COST
            // clean out invalid characters
            $cost = preg_replace('/[^0-9.]/', '',  $cost);
//echo 'USPS $cost: ' . $cost . ' $handling: ' . $handling . ' $hiddenCost: ' . $hiddenCost . ' $shipping_num_boxes: ' . $shipping_num_boxes . ' $usps_handling_fee: ' . $usps_handling_fee . '<br>';
            // add handling for shipping method costs for extra services applied
            $cost_original = $cost;
            $cost = ($cost + $handling + $hiddenCost) * $shipping_num_boxes;
            // add handling fee per Box or per Order
            $cost += (MODULE_SHIPPING_USPS_HANDLING_METHOD == 'Box') ? $usps_handling_fee * $shipping_num_boxes : $usps_handling_fee;

            // set the output title display name back to correct format
            $title = str_replace(array('RM', 'TM', '**'), array('&reg;', '&trade;', ''), $type_rebuilt);
//echo 'USPS title: ' . $title . '<br />';

            // process customization of transit times in quotes
            if (in_array('Display transit time', explode(', ', MODULE_SHIPPING_USPS_OPTIONS))) {
                if ($order->delivery['country']['id'] == SHIPPING_ORIGIN_COUNTRY || $this->is_us_shipment) {
                    if ($this->transitTimeCalculationMode != 'OLD') {
                        $this->parseDomesticTransitTimeResults($Package, $type_rebuilt);
                    }
                } else {
                    $this->parseIntlTransitTimeResults($Package, $type_rebuilt);
                }
            }

            // Add transit time -- if the transit time feature is enabled, then the transittime variable will not be blank, so this adds it. If it's disabled, will be blank, so adding here will have no negative effect.
            $title .= (isset($this->transittime[$type_rebuilt]) ? $this->transittime[$type_rebuilt] : '');

    //  $title .= '~' . $this->usps_countries;   // adds $this->usps_countries to title to test actual country

//echo 'USPS $type: ' . $type . (in_array($type, $this->typeCheckboxesSelected) ? ' YES' : ' NO') . ' $cost: ' . $cost . ' - $_SESSION[cart]->total: ' . $_SESSION['cart']->total . ' $handling: ' . $handling . ' $hiddenCost: ' . $hiddenCost . ' $usps_handling_fee: ' . $usps_handling_fee . ' $shipping_num_boxes: ' . $shipping_num_boxes . ' $title: ' . $title . '<br /><br />';
//echo 'USPS $type: ' . $type . (in_array($type, $this->typeCheckboxesSelected) ? ' YES' : ' NO') . ' $cost: ' . $cost . ' $title: ' . $title . '<br /><br />';
//echo 'USPS $type: ' . $type . (in_array($type, $this->typeCheckboxesSelected) ? ' YES' : ' NO') . ' $method: ' . $method . ' $usps_shipping_weight: ' . $usps_shipping_weight . ' $minweight: ' . $minweight . ' $maxweight: ' . $maxweight . '<br>';

// check First Class before validating
//  echo '<BR>$uspsQuote[Package][$i][FirstClassMailType]: ' . ($Package['FirstClassMailType'] == '' ? 'NONE' : $Package['FirstClassMailType']) . ' $type: ' . $type . ' $maxweight: ' . $maxweight . '<br>';


// check what we're validating
//echo '<br>$method: ' . $method . ' -- $type: ' . $type . ' -- RegEx: ' . $Package['lookupRegex'] . ' -- weight: ' . $usps_shipping_weight . ' --min: ' . $minweight . ' --max: ' . $maxweight . '<br>';


            // build USPS output for valid methods based on selected and weight limits
            if ($usps_shipping_weight <= $maxweight && $usps_shipping_weight > $minweight) {
                if ($method != $type && $method != $type_rebuilt) {
                    if ($method != '') {
                        continue;
                    }
                    $found = false;
                    foreach ($this->typeCheckboxesSelected as $key => $val) {
                        if (is_numeric($val) || $val == '') {
                            continue;
                        }
//echo '<br>before Line 521 $method: ' . $method . ' -- $val: ' . $val . ' -- RegEx: /' . $Package['lookupRegex'] . '/ -- weight: ' . $usps_shipping_weight . ' --min: ' . $minweight . ' --max: ' . $maxweight . '<br>';
                        if ($val == $type || preg_match('#' . $Package['lookupRegex'] . '#i', $val) ) {
                            $found = true;
                        }
                        if ($found === true) {
                            break;
                        }
                    }
                }
                if ($found === false) {
                    continue;
                }

// echo '<BR><BR>USPS VALID FOR QUOTE $type: ' . $type . ' $minweight: ' . $minweight . ' $maxweight: ' . $maxweight . ' Domestic[FirstClassMailType]: ' . $Package['FirstClassMailType'] . '<br><br>';

                // display 1st occurance of First Class and skip others for the US
          // echo 'USPS type: ' . $type . '<br>';
                if (preg_match('#First\-Class.*(?!GXG|International)#i', $type)) {
                    $cnt_first++;
                }

                // USPS customize for filtering displayed methods and costs
//echo '<br />USPS info: ' . '<br />$type: ' . $type . '<br />$type_rebuilt: ' . $type_rebuilt . '<br />$title: ' . $title . ' $cost: ' . $cost . '<br />';

                if ($this->is_us_shipment && MODULE_SHIPPING_USPS_FIRST_CLASS_FILTER_US == 'True' && preg_match('#First\-Class#i', $type) && $cnt_first > 1) {
                    continue;
                }

                // ADDITIONAL CUSTOMIZED CONDITIONS CAN GO HERE TO MANAGE $type_rebuilt or $title on $methods
// switch comment marks to show $hiddenCost: from quote title
//$show_hiddenCost =  ' $hiddenCost: ' . $hiddenCost;
                $show_hiddenCost = '';
                $methods[] = array(
                    'id' => $type_rebuilt,
                    'title' => $title . $show_hiddenCost,
                    'cost' => $cost,
                    'insurance' => $usps_insurance_charge,
                );
                $usps_shipping_quotes .= $title . "\n" . ' Original cost: ' . number_format($cost_original, 2) . ($hiddenCost ? ($this->is_us_shipment ? ' SpecialServices: ' : ' ExtraServices: ') . number_format($hiddenCost, 2) : '') . ' Total Cost: ' . number_format($cost, 2) . ($usps_insurance_charge ? ' - $usps_insurance_charge: ' . number_format($usps_insurance_charge, 2) : '') . "\n";
                $usps_shipping_quotes .= $hidden_costs_breakdown . "\n";
//echo 'setting insurance: ' . $usps_insurance_charge . '<br>';
            } else {
//echo 'MISSING! USPS $type: ' . $type . (in_array($type, $this->typeCheckboxesSelected) ? ' YES' : ' NO') . ' $method: ' . $method . ' $usps_shipping_weight: ' . $usps_shipping_weight . ' $minweight: ' . $minweight . ' $maxweight: ' . $maxweight . '<br>';
            }
        }  // end for $i to $PackageSize

        if (count($methods) == 0) {
            return false;
        }

        // add shipping quotes to logs
        if (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Logs') {
            global $logfilename;
            $fp = @fopen($logfilename, 'a');
            if ($fp) {
                $usps_shipping_quotes = str_replace(array('&amp;reg;', '&amp;trade;'), array('RM', 'TM'), htmlspecialchars($usps_shipping_quotes));
                fwrite($fp,  '==================================' . "\n\n" . 'Rate Quotes:' . "\n" . $usps_shipping_quotes . "\n\n");
                fclose($fp);
            }
        }

        // sort results
        if (MODULE_SHIPPING_USPS_QUOTE_SORT != 'Unsorted') {
            if (count($methods) > 1) {
                if (substr(MODULE_SHIPPING_USPS_QUOTE_SORT, 0, 5) == 'Price') {
                    foreach ($methods as $c => $key) {
                        $sort_cost[] = $key['cost'];
                        $sort_id[] = $key['id'];
                    }
                    array_multisort($sort_cost, (MODULE_SHIPPING_USPS_QUOTE_SORT == 'Price-LowToHigh' ? SORT_ASC : SORT_DESC), $sort_id, SORT_ASC, $methods);
                } else {
                    foreach ($methods as $c => $key) {
                        $sort_key[] = $key['title'];
                        $sort_id[] = $key['id'];
                    }
                    array_multisort($sort_key, (MODULE_SHIPPING_USPS_QUOTE_SORT == 'Alphabetical' ? SORT_ASC : SORT_DESC), $sort_id, SORT_ASC, $methods);
                }
            }
        }

        // Show box weight if enabled
        $show_box_weight = '';
        if (in_array('Display weight', explode(', ', MODULE_SHIPPING_USPS_OPTIONS))) {
            switch (SHIPPING_BOX_WEIGHT_DISPLAY) {
                case '0':
                    $show_box_weight = '';
                    break;
                case '1':
                    $show_box_weight = ' (' . $shipping_num_boxes . ' ' . TEXT_SHIPPING_BOXES . ')';
                    break;
                case '2':
                    $show_box_weight = ' (' . number_format($usps_shipping_weight * $shipping_num_boxes,2) . TEXT_SHIPPING_WEIGHT . ')';
                    break;
                default:
                    $show_box_weight = ' (' . $shipping_num_boxes . ' ' . TEXT_SHIPPING_BOXES . ')  (' . $this->pounds . ' lbs, ' . $this->ounces . ' oz' . ')';
                    break;
            }
        }
        $this->quotes = array(
            'id' => $this->code,
            'module' => $this->title . $show_box_weight,
            'methods' => $methods,
            'tax' => $this->tax_class > 0 ? zen_get_tax_rate($this->tax_class, $order->delivery['country']['id'], $order->delivery['zone_id']) : null,
        );

        // add icon/message, if any
        if ($this->icon != '') {
            $this->quotes['icon'] = zen_image($this->icon, $this->title);
        }
        if ($iInfo != '') {
            $this->quotes['icon'] .= '<br />' . $iInfo;
        }

        $this->notify('NOTIFY_SHIPPING_USPS_QUOTES_READY_TO_RETURN');
        return $this->quotes;
    }

    /**
     * check status of module
     *
     * @return boolean
     */
    public function check() 
    {
        global $db;
        if (!isset($this->_check)) {
            $check_query = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_SHIPPING_USPS_STATUS' LIMIT 1");
            $this->_check = $check_query->RecordCount();
        }
        global $sniffer;
        $results = $sniffer->field_type(TABLE_ORDERS, 'shipping_method', 'varchar(255)', true);
        if ($results !== true) {
            $sql = "ALTER TABLE " . TABLE_ORDERS . " MODIFY shipping_method varchar(255) NOT NULL DEFAULT ''";
            $db->Execute($sql);
        }

        $this->notify('NOTIFY_SHIPPING_USPS_CHECK');
        return $this->_check;
    }

    /**
     * Install this module
     */
    public function install() 
    {
        global $db;
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
             VALUES 
                ('USPS Version Date', 'MODULE_SHIPPING_USPS_VERSION', '2017-09-16', 'You have installed:', 6, 0, 'zen_cfg_select_option(array(\'2017-09-16\'), ', now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added)
             VALUES 
                ('Enable USPS Shipping', 'MODULE_SHIPPING_USPS_STATUS', 'True', 'Do you want to offer USPS shipping?', 6, 0, 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added)
             VALUES 
                ('Full Name or Short Name', 'MODULE_SHIPPING_USPS_TITLE_SIZE', 'Long', 'Do you want to use a Long or Short name for USPS shipping?', 6, 0, 'zen_cfg_select_option(array(\'Long\', \'Short\'), ', now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) 
             VALUES 
                ('Shipping Zone', 'MODULE_SHIPPING_USPS_ZONE', '0', 'If a zone is selected, only enable this shipping method for that zone.', 6, 0, 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) 
             VALUES 
                ('Sort Order', 'MODULE_SHIPPING_USPS_SORT_ORDER', '0', 'Sort order of display.', 6, 0, now())"
        );

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) 
             VALUES 
                ('Enter the USPS Web Tools User ID', 'MODULE_SHIPPING_USPS_USERID', 'NONE', 'Enter the USPS USERID assigned to you for Rate Quotes/ShippingAPI.', 6, 0, now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
             VALUES 
                ('Which server to use', 'MODULE_SHIPPING_USPS_SERVER', 'production', 'An account at USPS is needed to use the Production server', 6, 0, 'zen_cfg_select_option(array(\'test\', \'production\'), ', now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
             VALUES 
                ('All Packages are Machinable?', 'MODULE_SHIPPING_USPS_MACHINABLE', 'False', 'Are all products shipped machinable based on C700 Package Services 2.0 Nonmachinable PARCEL POST USPS Rules and Regulations?<br /><br /><strong>Note: Nonmachinable packages will usually result in a higher Parcel Post Rate Charge.<br /><br />Packages 35lbs or more, or less than 6 ounces (.375), will be overridden and set to False</strong>', 6, 0, 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
             VALUES 
                ('Quote Sort Order', 'MODULE_SHIPPING_USPS_QUOTE_SORT', 'Price-LowToHigh', 'Sorts the returned quotes using the service name Alphanumerically or by Price. Unsorted will give the order provided by USPS.', 6, 0, 'zen_cfg_select_option(array(\'Unsorted\',\'Alphabetical\', \'Price-LowToHigh\', \'Price-HighToLow\'), ', now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
             VALUES 
                ('Decimal Settings', 'MODULE_SHIPPING_USPS_DECIMALS', '3', 'Decimal Setting can be 1, 2 or 3. Sometimes International requires 2 decimals, based on Tare Rates or Product weights. Do you want to use 1, 2 or 3 decimals?', 6, 0, 'zen_cfg_select_option(array(\'1\', \'2\', \'3\'), ', now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) 
             VALUES 
                ('Tax Class', 'MODULE_SHIPPING_USPS_TAX_CLASS', '0', 'Use the following tax class on the shipping fee.', 6, 0, 'zen_get_tax_class_title', 'zen_cfg_pull_down_tax_classes(', now())"
        );

// fix here: module needs code for tax basis
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
             VALUES 
                ('Tax Basis', 'MODULE_SHIPPING_USPS_TAX_BASIS', 'Shipping', 'On what basis is Shipping Tax calculated. Options are<br />Shipping - Based on customers Shipping Address<br />Billing Based on customers Billing address<br />Store - Based on Store address if Billing/Shipping Zone equals Store zone', 6, 0, 'zen_cfg_select_option(array(\'Shipping\', \'Billing\', \'Store\'), ', now())"
        );

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
             VALUES 
                ('USPS Options', 'MODULE_SHIPPING_USPS_OPTIONS', '--none--', 'Select from the following the USPS options.<br />note: this adds a considerable delay in obtaining quotes.', '6', '16', 'zen_cfg_select_multioption(array(\'Display weight\', \'Display transit time\'), ',  now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
             VALUES 
                ('USPS Domestic Transit Time Calculation Mode', 'MODULE_SHIPPING_USPS_TRANSIT_TIME_CALCULATION_MODE', 'NEW', 'Select from the following the USPS options.<br />note: NEW and OLD will add additional time to quotes. CUSTOM allows your custom shipping days.', '6', '16', 'zen_cfg_select_option(array(\'CUSTOM\', \'NEW\', \'OLD\'), ',  now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
             VALUES 
                ('Debug Mode', 'MODULE_SHIPPING_USPS_DEBUG_MODE', 'Off', 'Would you like to enable debug mode?  A complete detailed log of USPS quote results may be emailed to the store owner, Log results or displayed to Screen.', 6, 0, 'zen_cfg_select_option(array(\'Off\', \'Email\', \'Logs\', \'Screen\'), ', now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) 
             VALUES 
                ('Handling Fee - US', 'MODULE_SHIPPING_USPS_HANDLING', '0', 'National Handling fee for this shipping method.', 6, 0, now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) 
             VALUES 
                ('Handling Fee - International', 'MODULE_SHIPPING_USPS_HANDLING_INT', '0', 'International Handling fee for this shipping method.', 6, 0, now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
             VALUES 
                ('Handling Per Order or Per Box', 'MODULE_SHIPPING_USPS_HANDLING_METHOD', 'Box', 'Do you want to charge Handling Fee Per Order or Per Box?', 6, 0, 'zen_cfg_select_option(array(\'Order\', \'Box\'), ', now())"
        );

        /*
        Small Flat Rate Box 8-5/8" x 5-3/8" x 1-5/8"
        Global Express Guaranteed - Min. length 9-1/2", height 5-1/2"
        MODULE_SHIPPING_USPS_LENGTH 8.625
        MODULE_SHIPPING_USPS_WIDTH  5.375
        MODULE_SHIPPING_USPS_HEIGHT 1.625
        */
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) 
             VALUES 
                ('USPS Domestic minimum Length', 'MODULE_SHIPPING_USPS_LENGTH', '8.625', 'The Minimum Length, Width and Height are used to determine shipping methods available for International Shipping.<br />While dimensions are not supported at this time, the Minimums are sent to USPS for obtaining Rate Quotes.<br />In most cases, these Minimums should never have to be changed.<br /><br /><strong>Enter the Domestic</strong><br />Minimum Length - default 8.625', 6, 0, now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) 
             VALUES 
                ('USPS minimum Width', 'MODULE_SHIPPING_USPS_WIDTH', '5.375', 'Enter the Minimum Width - default 5.375', 6, 0, now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) 
             VALUES 
                ('USPS minimum Height', 'MODULE_SHIPPING_USPS_HEIGHT', '1.625', 'Enter the Minimum Height - default 1.625', 6, 0, now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) 
             VALUES 
                ('USPS International minimum Length', 'MODULE_SHIPPING_USPS_LENGTH_INTL', '9.50', 'The Minimum Length, Width and Height are used to determine shipping methods available for International Shipping.<br />While dimensions are not supported at this time, the Minimums are sent to USPS for obtaining Rate Quotes.<br />In most cases, these Minimums should never have to be changed.<br /><br /><strong>Enter the International</strong><br />Minimum Length - default 9.50', 6, 0, now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) 
             VALUES 
                ('USPS minimum Width', 'MODULE_SHIPPING_USPS_WIDTH_INTL', '1.0', 'Enter the Minimum Width - default 1.0', 6, 0, now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) 
             VALUES 
                ('USPS minimum Height', 'MODULE_SHIPPING_USPS_HEIGHT_INTL', '5.50', 'Enter the Minimum Height - default 5.50', 6, 0, now())"
        );

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
             VALUES 
                ('Enable USPS First-Class filter for US shipping', 'MODULE_SHIPPING_USPS_FIRST_CLASS_FILTER_US', 'True', 'Do you want to enable the US First-Class filter to display only 1 First-Class shipping rate?', 6, 0, 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())"
        );
//    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Shipping Methods (Domestic and International)',  'MODULE_SHIPPING_USPS_TYPES',  '0, .21875, 0.00, 0, .8125, 0.00, 0, .8125, 0.00, 0, .8125, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 15, 0.00, 0, 20, 0.00, 0, 25, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, .21875, 0.00, 0, 4, 0.00, 0, 4, 0.00, 0, 66, 0.00, 0, 4, 0.00, 0, 4, 0.00, 0, 20, 0.00, 0, 20, 0.00, 0, 66, 0.00, 0, 4, 0.00, 0, 20, 0.00, 0, 70, 0.00, 0, 70, 0.00', '<b><u>Checkbox:</u></b> Select the services to be offered<br /><b><u>Minimum Weight (lbs)</u></b>first input field<br /><b><u>Maximum Weight (lbs):</u></b>second input field<br /><br />USPS returns methods based on cart weights.  These settings will allow further control (particularly helpful for flat rate methods) but will not override USPS limits', 6, 0, 'zen_cfg_usps_services(array(\'First-Class Mail Letter\', \'First-Class Mail Large Envelope\', \'First-Class Mail Parcel\', \'First-ClassTM Package Service\', \'Media Mail Parcel\', \'Standard PostRM\', \'Priority MailTM\', \'Priority MailTM Flat Rate Envelope\', \'Priority MailTM Legal Flat Rate Envelope\', \'Priority MailTM Padded Flat Rate Envelope\', \'Priority MailTM Small Flat Rate Box\', \'Priority MailTM Medium Flat Rate Box\', \'Priority MailTM Large Flat Rate Box\', \'Priority MailTM Regional Rate Box A\', \'Priority MailTM Regional Rate Box B\', \'Priority MailTM Regional Rate Box C\', \'Priority Mail ExpressTM\', \'Priority Mail ExpressTM Flat Rate Envelope\', \'Priority Mail ExpressTM Legal Flat Rate Envelope\', \'Priority Mail ExpressTM Flat Rate Boxes\', \'First-Class MailRM International Letter\', \'First-Class MailRM International Large Envelope\', \'First-Class Package International ServiceTM\', \'Priority Mail InternationalRM\', \'Priority Mail InternationalRM Flat Rate Envelope\', \'Priority Mail InternationalRM Small Flat Rate Box\', \'Priority Mail InternationalRM Medium Flat Rate Box\', \'Priority Mail InternationalRM Large Flat Rate Box\', \'Priority Mail Express InternationalTM\', \'Priority Mail Express InternationalTM Flat Rate Envelope\', \'Priority Mail Express InternationalTM Flat Rate Boxes\', \'USPS GXGTM Envelopes\', \'Global Express GuaranteedRM (GXG)\'), ', now())");
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
             VALUES 
                ('Shipping Methods (Domestic and International)',  'MODULE_SHIPPING_USPS_TYPES',  '0, .21875, 0.00, 0, .8125, 0.00, 0, .8125, 0.00, 0, .9375, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 15, 0.00, 0, 20, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, 70, 0.00, 0, .21875, 0.00, 0, 4, 0.00, 0, 4, 0.00, 0, 66, 0.00, 0, 4, 0.00, 0, 4, 0.00, 0, 20, 0.00, 0, 20, 0.00, 0, 66, 0.00, 0, 4, 0.00, 0, 70, 0.00, 0, 70, 0.00', '<b><u>Checkbox:</u></b> Select the services to be offered<br /><b><u>Minimum Weight (lbs)</u></b>first input field<br /><b><u>Maximum Weight (lbs):</u></b>second input field<br /><br />USPS returns methods based on cart weights.  These settings will allow further control (particularly helpful for flat rate methods) but will not override USPS limits', 6, 0, 'zen_cfg_usps_services(array(\'First-Class Mail Letter\', \'First-Class Mail Large Envelope\', \'First-Class Package Service - RetailTM\', \'First-ClassTM Package Service\', \'Media Mail Parcel\', \'USPS Retail GroundRM\', \'Priority MailTM\', \'Priority MailTM Flat Rate Envelope\', \'Priority MailTM Legal Flat Rate Envelope\', \'Priority MailTM Padded Flat Rate Envelope\', \'Priority MailTM Small Flat Rate Box\', \'Priority MailTM Medium Flat Rate Box\', \'Priority MailTM Large Flat Rate Box\', \'Priority MailTM Regional Rate Box A\', \'Priority MailTM Regional Rate Box B\', \'Priority Mail ExpressTM\', \'Priority Mail ExpressTM Flat Rate Envelope\', \'Priority Mail ExpressTM Legal Flat Rate Envelope\', \'First-Class MailRM International Letter\', \'First-Class MailRM International Large Envelope\', \'First-Class Package International ServiceTM\', \'Priority Mail InternationalRM\', \'Priority Mail InternationalRM Flat Rate Envelope\', \'Priority Mail InternationalRM Small Flat Rate Box\', \'Priority Mail InternationalRM Medium Flat Rate Box\', \'Priority Mail InternationalRM Large Flat Rate Box\', \'Priority Mail Express InternationalTM\', \'Priority Mail Express InternationalTM Flat Rate Envelope\', \'USPS GXGTM Envelopes\', \'Global Express GuaranteedRM (GXG)\'), ', now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
             VALUES 
                ('Extra Services (Domestic)', 'MODULE_SHIPPING_USPS_DMST_SERVICES', 'Certified MailRM, N, USPS TrackingTM Electronic, N, USPS TrackingTM, N, Insurance, N, Priority Mail Express Insurance, N, Priority Mail Insurance, N, Adult Signature Restricted Delivery, N, Adult Signature Required, N, Registered MailTM, N, Collect on Delivery, N, Return Receipt for Merchandise, N, Return Receipt, N, Certificate of Mailing (Form 3665), N, Certificate of Mailing (Form 3817), N, Signature ConfirmationTM Electronic, N, Signature ConfirmationTM, N, Priority Mail Express 1030 AM Delivery, N, Certified MailRM Restricted Delivery, N, Certified MailRM Adult Signature Required, N, Certified MailRM Adult Signature Restricted Delivery, N, Signature ConfirmationTM Restricted Delivery, N, Signature ConfirmationTM Electronic Restricted Delivery, N, Collect on Delivery Restricted Delivery, N, Registered MailTM Restricted Delivery, N, Insurance Restricted Delivery, N, Insurance Restricted Delivery (Priority Mail Express), N, Insurance Restricted Delivery (Priority Mail), N', 'Included in postage rates.  Not shown to the customer.<br />WARNING: Some services cannot work with other services.', 6, 0, 'zen_cfg_usps_extraservices(array(\'Certified MailRM\', \'USPS TrackingTM Electronic\', \'USPS TrackingTM\', \'Insurance\', \'Priority Mail Express Insurance\', \'Priority Mail Insurance\', \'Adult Signature Restricted Delivery\', \'Adult Signature Required\', \'Registered MailTM\', \'Collect on Delivery\', \'Return Receipt for Merchandise\', \'Return Receipt\', \'Certificate of Mailing (Form 3665)\', \'Certificate of Mailing (Form 3817)\', \'Signature ConfirmationTM Electronic\', \'Signature ConfirmationTM\', \'Priority Mail Express 1030 AM Delivery\', \'Certified MailRM Restricted Delivery\', \'Certified MailRM Adult Signature Required\', \'Certified MailRM Adult Signature Restricted Delivery\', \'Signature ConfirmationTM Restricted Delivery\', \'Signature ConfirmationTM Electronic Restricted Delivery\', \'Collect on Delivery Restricted Delivery\', \'Registered MailTM Restricted Delivery\', \'Insurance Restricted Delivery\', \'Insurance Restricted Delivery (Priority Mail Express)\', \'Insurance Restricted Delivery (Priority Mail)\'), ', now())"
        );
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
             VALUES 
                ('Extra Services (International)', 'MODULE_SHIPPING_USPS_INTL_SERVICES', 'Registered Mail, N, Insurance, N, Return Receipt, N, Electronic USPS Delivery Confirmation International, N, Certificate of Mailing, N', 'Included in postage rates.  Not shown to the customer.<br />WARNING: Some services cannot work with other services.', 6, 0, 'zen_cfg_usps_extraservices(array(\'Registered Mail\', \'Insurance\', \'Return Receipt\', \'Electronic USPS Delivery Confirmation International\', \'Certificate of Mailing\'), ', now())"
        );

        // Special Services prices and availability will not be returned when Service = ALL or ONLINE
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
             VALUES 
                ('Retail pricing or Online pricing?', 'MODULE_SHIPPING_USPS_RATE_TYPE', 'Online', 'Rates will be returned ONLY for methods available in this pricing type.  Applies to prices <u>and</u> add on services', 6, 0, 'zen_cfg_select_option(array(\'Retail\', \'Online\'), ', now())"
        );
        $this->notify('NOTIFY_SHIPPING_USPS_INSTALLED');
    }

    /**
     * For removing this module's settings
     */
    public function remove() 
    {
        global $db;
        $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key LIKE 'MODULE\_SHIPPING\_USPS\_%' ");
        $this->notify('NOTIFY_SHIPPING_USPS_UNINSTALLED');
    }

    /**
     * Build array of keys used for installing/managing this module
     *
     * @return array
     */
    public function keys() 
    {
        $keys_list = array(
            'MODULE_SHIPPING_USPS_VERSION', 
            'MODULE_SHIPPING_USPS_STATUS', 
            'MODULE_SHIPPING_USPS_TITLE_SIZE', 
            'MODULE_SHIPPING_USPS_USERID', 
            'MODULE_SHIPPING_USPS_SERVER', 
            'MODULE_SHIPPING_USPS_QUOTE_SORT', 
            'MODULE_SHIPPING_USPS_HANDLING', 
            'MODULE_SHIPPING_USPS_HANDLING_INT', 
            'MODULE_SHIPPING_USPS_HANDLING_METHOD', 
            'MODULE_SHIPPING_USPS_DECIMALS', 
            'MODULE_SHIPPING_USPS_TAX_CLASS', 
            'MODULE_SHIPPING_USPS_TAX_BASIS', 
            'MODULE_SHIPPING_USPS_ZONE', 
            'MODULE_SHIPPING_USPS_SORT_ORDER', 
            'MODULE_SHIPPING_USPS_MACHINABLE', 
            'MODULE_SHIPPING_USPS_OPTIONS', 
            'MODULE_SHIPPING_USPS_TRANSIT_TIME_CALCULATION_MODE', 
            'MODULE_SHIPPING_USPS_LENGTH', 
            'MODULE_SHIPPING_USPS_WIDTH', 
            'MODULE_SHIPPING_USPS_HEIGHT', 
            'MODULE_SHIPPING_USPS_LENGTH_INTL', 
            'MODULE_SHIPPING_USPS_WIDTH_INTL', 
            'MODULE_SHIPPING_USPS_HEIGHT_INTL', 
            'MODULE_SHIPPING_USPS_FIRST_CLASS_FILTER_US', 
            'MODULE_SHIPPING_USPS_TYPES', 
            'MODULE_SHIPPING_USPS_DMST_SERVICES', 
            'MODULE_SHIPPING_USPS_INTL_SERVICES', 
            'MODULE_SHIPPING_USPS_RATE_TYPE',
            'MODULE_SHIPPING_USPS_DEBUG_MODE',
        );
        $this->notify('NOTIFY_SHIPPING_USPS_KEYS', '', $keys_list);
        return $keys_list;
    }

    /**
     * Get actual quote from USPS
     *
     * @return array of results or boolean false if no results
     */
    protected function _getQuote() 
    {
        global $order, $shipping_weight, $currencies, $logfilename;
        
        // -----
        // If the order's tax-value isn't set (like when a quote is requested from
        // the shipping-estimator), set that value to 0 to prevent follow-on PHP
        // notices from this module's quote processing.
        //
        if (!isset($order->info['tax'])) {
            $order->info['tax'] = 0;
        }
        
        $package_id = 'USPS DOMESTIC RETURNED: ' . "\n";
        
        // force GroundOnly results in USPS Retail Ground only being offered
        // to use, you must have a field for products_groundonly in the products table
        // then uncomment the in_cart_check for the products_groundonly
        // when $usps_groundonly is set to true, only USPS Retail Ground will show
        $usps_groundonly = 'false';
//    $usps_groundonly = ($_SESSION['cart']->in_cart_check('products_groundonly','1') ? 'true' : 'false');
        if ($usps_groundonly == 'false') {
            // no GroundOnly products
            $usps_groundonly = '';
        } else {
            // 1+ GroundOnly products force USPS Retail Ground only
            $usps_groundonly = '<Content><ContentType>HAZMAT</ContentType></Content><GroundOnly>' . $usps_groundonly . '</GroundOnly>';
        }

        // Force Fragile
        // to use, you must have a field for products_fragile in the products table
        // then uncomment the in_cart_check for the products_fragile
        // when $usps_fragile is set to true, Fragile Rates will be returned
        $usps_fragile = 'false';
//    $usps_fragile = ($_SESSION['cart']->in_cart_check('products_fragile','1') ? 'true' : 'false');
        if ($usps_fragile == 'false') {
            $usps_fragile = '<Content><ContentType>Fragile</ContentType></Content>';
        }

//echo 'USPS function _getQuote $this->enabled: ' . ($this->enabled ? ' ON' : ' OFF') . ' $shipping_weight: ' . $shipping_weight . '<br>';

        if ((int)SHIPPING_ORIGIN_ZIP == 0) {
            // no quotes obtained no 5 digit zip code origin set
            return array(
                'module' => $this->title,
                'error' => MODULE_SHIPPING_USPS_TEXT_ERROR . (MODULE_SHIPPING_USPS_SERVER == 'test' ? MODULE_SHIPPING_USPS_TEXT_TEST_MODE_NOTICE : '')
            );
        }
        if (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Logs') {
            $usps_instance_id = date('mdYGis');
            $usps_dir_logs = (defined('DIR_FS_LOGS') ? DIR_FS_LOGS : DIR_FS_SQL_CACHE);
            $logfilename = $usps_dir_logs . '/SHIP_usps_Debug_' . $usps_instance_id . '_' . str_replace(' ', '', $order->delivery['country']['iso_code_2']) . '_' . str_replace(' ', '', $order->delivery['postcode']) . '_' . $this->pounds . 'lb_' . $this->ounces . 'oz' . '.log';
        }

        $transreq = array();

        if (MODULE_SHIPPING_USPS_DEBUG_MODE != 'Off') {
            // display checked boxes
            $usps_shipping_methods_domestic = '';
            $usps_shipping_methods_international = '';
            $usps_shipping_country = "\n" . '==================================' . "\n\n" . 'USPS Country - $this->countries[$order->delivery[country][iso_code_2]]: ' . $this->countries[$order->delivery['country']['iso_code_2']] . ' $this->usps_countries: ' . $this->usps_countries . "\n";
            if ($this->is_us_shipment) {
                $package_id_sent = 0;
                $usps_shipping_methods_domestic .= '<br />USPS DOMESTIC CHECKED: ' . MODULE_SHIPPING_USPS_RATE_TYPE . '<br />';
                foreach ($this->typeCheckboxesSelected as $key => $val) {
                    $requested_type = $this->typeCheckboxesSelected[$key];
//echo '$requested_type: ' . $requested_type . ' $key: ' . $key . ' $val: ' . $val . '<br>';
                    $checked_request = ' min: ' . $this->typeCheckboxesSelected[$key+1] . ' max: ' . $this->typeCheckboxesSelected[$key+2] . ' handling: ' . $this->typeCheckboxesSelected[$key+3];
                    if (is_numeric($requested_type) || empty($requested_type) || preg_match('#(GXG|International)#i', $requested_type)) {
                        continue;
                    }
                    $usps_shipping_methods_domestic .= 'Package ID sent: ' . $package_id_sent . ' ' . $requested_type . ' - ' . $checked_request . "\n";
                    $package_id_sent++;
                }
            } else {
                $usps_shipping_methods_international .= '<br />USPS INTERNATIONAL CHECKED: ' . MODULE_SHIPPING_USPS_RATE_TYPE . '<br />';
                foreach ($this->typeCheckboxesSelected as $key => $val) {
                    $requested_type = $this->typeCheckboxesSelected[$key];
                    $checked_request = ' min: ' . $this->typeCheckboxesSelected[$key+1] . ' max: ' . $this->typeCheckboxesSelected[$key+2] . ' handling: ' . $this->typeCheckboxesSelected[$key+3];
                    if (is_numeric($requested_type) || empty($requested_type) || !preg_match('#(GXG|International)#i', $requested_type) ) {
                        continue;
                    }
                    $usps_shipping_methods_international .= $requested_type . ' - ' . $checked_request . "\n";
                }
            }

            // TURNED OFF WITH FALSE
            if (false && $_GET['main_page'] == 'popup_shipping_estimator' && MODULE_SHIPPING_USPS_DEBUG_MODE != 'Off') {
                echo '================================' . '<br />';
                echo $usps_shipping_methods_domestic;
                echo $usps_shipping_methods_international;
                echo '================================' . '<br />';
            }
        }

        //@TODO
        // reduce order value by products not shipped
        // should this be an option or automatic?
        // International uses $max_usps_allowed_price which is total/boxes not correct for insurance quotes?

        // reduce order value for virtual, downloads and Gift Certificates
        if (true) {
            $shipment_value = $order->info['subtotal'] > 0 ? $order->info['subtotal'] + $order->info['tax'] : $_SESSION['cart']->total;
            global $uninsurable_value;
            $insurable_value = $shipment_value - $uninsurable_value;
        //echo 'USPS total order value: ' . $shipment_value . '<br>';
        //echo 'USPS reduce order value: ' . $uninsurable_value . '<br>';
        //echo 'USPS insurable order value: ' . $insurable_value . '<br>';
        }

        // US Domestic destinations
        if ($order->delivery['country']['id'] == SHIPPING_ORIGIN_COUNTRY || $this->is_us_shipment) {
            // build special services for domestic
            // Some Special Services cannot work with others
            $special_services_domestic = $this->special_services(); // original

            // -----
            // If the delivery postcode isn't set (like during the shipping-estimator), set it to
            // an empty string to cause 'no quote' to be returned.
            //
            if (!isset($order->delivery['postcode'])) {
                $order->delivery['postcode'] = '';
            }
            $ZipDestination = substr(str_replace(' ', '', $order->delivery['postcode']), 0, 5);
            if ($ZipDestination == '') {
                return -1;
            }
            $request =  '<RateV4Request USERID="' . MODULE_SHIPPING_USPS_USERID . '">' . '<Revision>2</Revision>';
            $package_count = 0;
            $ship_date = $this->zen_usps_shipdate();

            foreach($this->typeCheckboxesSelected as $requested_type) {
//echo 'USPS $requested_type: ' . $requested_type . '<br>';
                if (is_numeric($requested_type) || preg_match('#(GXG|International)#i' , $requested_type)) {
                    continue;
                }
                $FirstClassMailType = '';
                $Container = 'VARIABLE';
                if (preg_match('#First\-Class#i', $requested_type)) {
                    // disable request for all First Class at 13oz. - First-Class MailRM Letter, First-Class MailRM Large Envelope, First-Class MailRM Parcel
                    // disable request for all First Class at 13oz. - First-Class Mail Letter, First-Class Mail Large Envelope, First-Class Package Service - RetailTM
                    // disable all first class requests if item is over 15oz.
                    // First-Class Retail and Commercial
                    if ($shipping_weight > 15/16) {
                        continue;
                    } else {
                        // First-Class MailRM Letter\', \'First-Class MailRM Large Envelope\', \'First-Class Package Service - RetailTM
                        $service = 'First Class';            
                        // disable request for First-Class MailRM Letter at > .21875 and not Retail
                        if (($requested_type == 'First-Class Mail Letter') && (MODULE_SHIPPING_USPS_RATE_TYPE == 'Retail') && ($shipping_weight <= .21875)) {
                          $FirstClassMailType = 'LETTER';
                        // disable request for First-Class Mail Large Envelope at > 13oz and not Retail  
                        } elseif (($requested_type == 'First-Class Mail Large Envelope') && (MODULE_SHIPPING_USPS_RATE_TYPE == 'Retail') && ($shipping_weight <= 13/16)) {
                          $FirstClassMailType = 'FLAT';
                        // disable request for First-Class Package Service - RetailTM(new retail parcel designation) at > 13oz and not Retail               
                        } elseif (($requested_type == 'First-Class Package Service - RetailTM') && (MODULE_SHIPPING_USPS_RATE_TYPE == 'Retail') && ($shipping_weight <= 13/16)) {
                           $FirstClassMailType = 'PARCEL';    
                        // disable request for First-ClassTM Package Service(existing commercial parcel designation) at > 1 lb and not Online(commercial pricing)
                        } elseif (($requested_type == 'First-ClassTM Package Service') && (MODULE_SHIPPING_USPS_RATE_TYPE == 'Online') && ($shipping_weight <= 15/16)) {
                         $service = 'First Class Commercial';
                           $FirstClassMailType = 'PACKAGE SERVICE';
                        } else {
                          continue;
                        }
                    }
                } elseif ($requested_type == 'Media Mail Parcel') {
                    $service = 'MEDIA';
                } elseif ($requested_type == 'USPS Retail GroundRM') {
                    $service = 'PARCEL';
                } elseif (preg_match('#Priority Mail(?! Express)#i', $requested_type)) {
//echo 'PRIORITY $requested_type: ' . $requested_type . '<br>';
                    $service = 'PRIORITY COMMERCIAL';
                    if ($requested_type == 'Priority MailTM Flat Rate Envelope') {
                        $Container = 'FLAT RATE ENVELOPE';
                    } elseif ($requested_type == 'Priority MailTM Legal Flat Rate Envelope') {
                        $Container = 'LEGAL FLAT RATE ENVELOPE';
                    } elseif ($requested_type == 'Priority MailTM Padded Flat Rate Envelope') {
                        $Container = 'PADDED FLAT RATE ENVELOPE';
                    } elseif ($requested_type == 'Priority MailTM Small Flat Rate Box') {
                        $Container = 'SM FLAT RATE BOX';
                    } elseif ($requested_type == 'Priority MailTM Medium Flat Rate Box') {
                        $Container = 'MD FLAT RATE BOX';
                    } elseif ($requested_type == 'Priority MailTM Large Flat Rate Box') {
                        $Container = 'LG FLAT RATE BOX';
                    } elseif ($requested_type == 'Priority MailTM Regional Rate Box A') {
                        $Container = 'REGIONALRATEBOXA';
                    } elseif ($requested_type == 'Priority MailTM Regional Rate Box B') {
                        $Container = 'REGIONALRATEBOXB';
                    }
                } elseif (preg_match('#Priority Mail Express#i', $requested_type)) {
//echo 'EXPRESS $requested_type: ' . $requested_type . '<br>';
                    $service = 'EXPRESS COMMERCIAL';
                    if ($requested_type == 'Priority Mail ExpressTM Flat Rate Envelope') {
                        $Container = 'FLAT RATE ENVELOPE';
                    } elseif ($requested_type == 'Priority Mail ExpressTM Legal Flat Rate Envelope') {
                        $Container = 'LEGAL FLAT RATE ENVELOPE';
//          } elseif ($requested_type == 'Priority Mail ExpressTM Flat Rate Boxes') {
//            $Container = 'FLAT RATE BOX';
                    }
                } else {
                    continue;
                }

                // build special services for domestic
                $specialservices = $special_services_domestic;

                // uncomment to force turn off SpecialServices requests completely
                //$specialservices = '';

                $width = MODULE_SHIPPING_USPS_WIDTH;
                $length = MODULE_SHIPPING_USPS_LENGTH;
                $height = MODULE_SHIPPING_USPS_HEIGHT;
                $girth = 108;

                // turn on dimensions
                $dimensions = '<Width>' . $width . '</Width>' .
                              '<Length>' . $length . '</Length>' .
                              '<Height>' . $height . '</Height>' .
                              '<Girth>' . $girth . '</Girth>';

                // uncomment to force turn off dimensions
                $dimensions = '';

//echo 'USPS ' . ' Package ID: ' . $package_count . ' $requested_type: ' . $requested_type . ' $service: ' . $service . ' $Container: ' . $Container . '<br>';
                $request .=  '<Package ID="' . $package_count . '">' .
//                     '<Service>ALL</Service>' .
//                     '<Service>ONLINE</Service>' .
                     '<Service>' . $service . '</Service>' .
                     ($FirstClassMailType != '' ? '<FirstClassMailType>' . $FirstClassMailType . '</FirstClassMailType>' : '') .
                     '<ZipOrigination>' . SHIPPING_ORIGIN_ZIP . '</ZipOrigination>' .
                     '<ZipDestination>' . $ZipDestination . '</ZipDestination>' .
                     '<Pounds>' . $this->pounds . '</Pounds>' .
                     '<Ounces>' . $this->ounces . '</Ounces>' .
                     '<Container>' . $Container . '</Container>' .
                     '<Size>REGULAR</Size>' .
                    $dimensions .
                    '<Value>' . number_format($insurable_value, 2, '.', '') . '</Value>' .
                    $specialservices .
                    ($usps_groundonly != '' ? $usps_groundonly : '') .
                     '<Machinable>' . ($this->machinable == 'True' ? 'TRUE' : 'FALSE') . '</Machinable>' .
//'<DropOffTime>23:59</DropOffTime>' .
                     ($this->getTransitTime && $this->transitTimeCalculationMode == 'NEW' ? '<ShipDate>' . $ship_date . '</ShipDate>' : '') .
                     '</Package>';

                $package_id .= 'Package ID returned: ' . $package_count . ' $requested_type: ' . $requested_type . ' $service: ' . $service . ' $Container: ' . $Container . "\n";
                $package_count++;

                // ask for Domestic transit times using old double-request method to individual USPS API for each shipping service requested
                if ($this->getTransitTime && $this->transitTimeCalculationMode == 'OLD') {
                    $transitreq  = 'USERID="' . MODULE_SHIPPING_USPS_USERID . '">' . '<OriginZip>' . SHIPPING_ORIGIN_ZIP . '</OriginZip>' . '<DestinationZip>' . $ZipDestination . '</DestinationZip>';
  //echo 'USPS $service: ' . $service . '<br>';
                    switch ($service) {
//             case 'EXPRESS COMMERCIAL':
//             case 'EXPRESS':  $transreq[$requested_type] = 'API=ExpressMail&XML=' . urlencode( '<ExpressMailRequest ' . $transitreq . '</ExpressMailRequest>');
//             break;
                        case 'PRIORITY COMMERCIAL':
                        case 'PRIORITY': $transreq[$requested_type] = 'API=PriorityMail&XML=' . urlencode( '<PriorityMailRequest ' . $transitreq . '</PriorityMailRequest>');
                        break;
                        case 'PARCEL':   $transreq[$requested_type] = 'API=StandardB&XML=' . urlencode( '<StandardBRequest ' . $transitreq . '</StandardBRequest>');
                        break;
                        case 'First-Class Mail':$transreq[$requested_type] = 'API=FirstClassMail&XML=' . urlencode( '<FirstClassMailRequest ' . $transitreq . '</FirstClassMailRequest>');
                        break;
                        case 'MEDIA':
                        default:         $transreq[$requested_type] = '';
                        break;
                    }
                }
            }

            $request .=  '</RateV4Request>';

            if (MODULE_SHIPPING_USPS_DEBUG_MODE != 'Off') {
                // prepare request for display
                $this->request_display = preg_replace(array('/<\//', '/></', '/>  </', '/</', '/>/', '/&gt;  &lt;/', '/&gt;&lt;/'), array('&lt;/', '&gt;&lt;', '&gt;  &lt;', '&lt;', '&gt;', '&gt;<br>  &lt;', '&gt;<br>&lt;'), htmlspecialchars_decode($request));

                // TURNED OFF WITH FALSE
                if (false && $_GET['main_page'] == 'popup_shipping_estimator' && MODULE_SHIPPING_USPS_DEBUG_MODE != 'Off') {
                    echo '<br />USPS DOMESTIC $request: <br />' . 'API=RateV4&XML=' . $this->request_display . '<br />';
                }
                // prepare request for debug log
                $this->request_display = $request;
            }

            $request = 'API=RateV4&XML=' . urlencode($request);

        } else {
            // INTERNATIONAL destinations
//echo 'USPS SHIPPING_ORIGIN_ZIP: ' . SHIPPING_ORIGIN_ZIP . '<br />';

// for Machinable Rates place below Ounces
// NOTE: With Machinable set Rates will not show for: First-Class Mail International Large Envelope
// NOTE: With Machinable set Rates will be higher for: First-Class Mail International Letter
//                '<Machinable>' . ($this->machinable == 'True' ? 'True' : 'False') . '</Machinable>' .
//echo 'Country id: ' . $order->delivery['country']['id'] . ' name: ' . zen_get_country_name($order->delivery['country']['id']) . ' lookup: ' . $this->usps_countries . '<br>';
//echo 'USPS ISO code: ' . $order->delivery['country']['iso_code_2'] . ' country: ' . $this->countries[$order->delivery['country']['iso_code_2']] . ' vs ' . zen_get_country_name($order->delivery['country']['id']) . (empty($this->countries[$order->delivery['country']['iso_code_2']]) ? ' EMPTY' : ' Something') .  '<br>';

// DestinationPostalCode is currently option, but this may change in the future
// associated with this maybe the AcceptanceDateTime with a valid XsdDateTime - <AcceptanceDateTime>2015-05-22T13:15:00-06:00</AcceptanceDateTime>
// when required, this will not allow black DestinationPostalCode
//      $DestinationPostalCode = trim($order->delivery['postcode']);
//      if ($DestinationPostalCode == '') return -1; // allow blank DestinationPostalCode

            // build extra services for international
            // Some Extra Services cannot work with others
            $extra_service_international = $this->extra_service(); // original

            $intl_gxg_requested = 0;
            foreach ($this->typeCheckboxesSelected as $requested_type) {
                if (!is_numeric($requested_type) && preg_match('#(GXG)#i', $requested_type)) {
                    $intl_gxg_requested++;
                }
            }

            // rudimentary dimensions, since they cannot be passed as blanks
            if (true || $intl_gxg_requested) {
        //@@TODO - force International to always use International settings - should be okay to make permanent at a future date
// obtain the most International settings
//        $width = 1.0; // $width = 0.75 for some International Methods to work
//        $length = 9.5;
//        $height = 5.5;
                $width = MODULE_SHIPPING_USPS_WIDTH_INTL;
                $length = MODULE_SHIPPING_USPS_LENGTH_INTL;
                $height = MODULE_SHIPPING_USPS_HEIGHT_INTL;
                $girth = 0;
            } else {
                $width = MODULE_SHIPPING_USPS_WIDTH;
                $length = MODULE_SHIPPING_USPS_LENGTH;
                $height = MODULE_SHIPPING_USPS_HEIGHT;
                $girth = 0;
            }

            // adjust <ValueOfContents> to not exceed $2499 per box
            global $shipping_num_boxes;
            $max_usps_allowed_price = ($order->info['subtotal'] > 0 ? $order->info['subtotal'] + $order->info['tax'] : $_SESSION['cart']->total);
//echo 'USPS International Boxes: ' . $shipping_num_boxes . '<br>';
//echo 'Original Price: ' . number_format($max_usps_allowed_price, 2, '.', '') . '<br>';
            $max_usps_allowed_price = ($max_usps_allowed_price/$shipping_num_boxes);
//echo 'New Maximum Price per box: ' . number_format($max_usps_allowed_price, 2, '.', '') . '<br><br>';

            // build extra services for international
            $extraservices = $extra_service_international;

            // uncomment to force turn off ExtraServices
            // $extraservices = '';

// $max_usps_allowed_price - adjust <ValueOfContents> to not exceed $2499 per box
            $submission_value = ($insurable_value > $max_usps_allowed_price) ? $max_usps_allowed_price : $insurable_value;

            $request =  '<IntlRateV2Request USERID="' . MODULE_SHIPPING_USPS_USERID . '">' .
                  '<Revision>2</Revision>' .
                  '<Package ID="0">' .
                  '<Pounds>' . $this->pounds . '</Pounds>' .
                  '<Ounces>' . $this->ounces . '</Ounces>' .
                  '<MailType>All</MailType>' .
                  '<GXG>' .
                  '  <POBoxFlag>N</POBoxFlag>' .
                  '  <GiftFlag>N</GiftFlag>' .
                  '</GXG>' .
//                  '<ValueOfContents>' . number_format($order->info['subtotal'] > 0 ? $order->info['subtotal'] + $order->info['tax'] : $_SESSION['cart']->total, 2, '.', '') . '</ValueOfContents>' .
//                  '<ValueOfContents>' . number_format(2499 > 0 ? 2499 : 2499, 2, '.', '') . '</ValueOfContents>' .
                  '<ValueOfContents>' . number_format($submission_value, 2, '.', '') . '</ValueOfContents>' .
                  '<Country>' . (empty($this->countries[$order->delivery['country']['iso_code_2']]) ? zen_get_country_name($order->delivery['country']['id']) : $this->countries[$order->delivery['country']['iso_code_2']]) . '</Country>' .
                  '<Container>RECTANGULAR</Container>' .
                  '<Size>REGULAR</Size>' .
// Small Flat Rate Box - 'maxLength'=>'8.625', 'maxWidth'=>'5.375','maxHeight'=>'1.625'
// Global Express Guaranteed - Minimum 'minLength'=>'9.5', 'minHeight'=>'5.5' ; Maximum - 'maxLength'=>'46', 'maxWidth'=>'35', 'maxHeight'=>'46' and max. length plus girth combined 108"
// NOTE: sizes for Small Flat Rate Box prevent Global Express Guaranteed
// NOTE: sizes for Global Express Guaranteed prevent Small Flat Rate Box
// Not set up:
// Video - 'maxLength'=>'9.25', 'maxWidth'=>'6.25','maxHeight'=>'2'
// DVD - 'maxLength'=>'7.5625', 'maxWidth'=>'5.4375','maxHeight'=>'.625'
// defaults
// MODULE_SHIPPING_USPS_LENGTH 8.625
// MODULE_SHIPPING_USPS_WIDTH  5.375
// MODULE_SHIPPING_USPS_HEIGHT 1.625
                  '<Width>' . $width . '</Width>' .
                  '<Length>' . $length . '</Length>' .
                  '<Height>' . $height . '</Height>' .
                  '<Girth>' . $girth . '</Girth>' .

//'<CommercialPlusFlag>N</CommercialPlusFlag>' .
                  '<OriginZip>' . SHIPPING_ORIGIN_ZIP . '</OriginZip>' .
                  // In the following line, changed N to Y to activate optional commercial base pricing for international services - 01/27/13 a.forever edit
                  '<CommercialFlag>Y</CommercialFlag>' .
// '<AcceptanceDateTime>2015-05-30T13:15:00-06:00</AcceptanceDateTime>' .
// '<DestinationPostalCode>' . $DestinationPostalCode . '</DestinationPostalCode>' .
                    $extraservices .
                  '</Package>' .
                  '</IntlRateV2Request>';

            if ($this->getTransitTime) {
                $transreq[$requested_type] = '';
            }

            if (MODULE_SHIPPING_USPS_DEBUG_MODE != 'Off') {
                // prepare request for display
                $this->request_display = preg_replace(array('/<\//', '/></', '/>  </', '/</', '/>/', '/&gt;  &lt;/', '/&gt;&lt;/'), array('&lt;/', '&gt;&lt;', '&gt;  &lt;', '&lt;', '&gt;', '&gt;<br>  &lt;', '&gt;<br>&lt;'), htmlspecialchars_decode($request));

                // TURNED OFF WITH FALSE
                if (false && $_GET['main_page'] == 'popup_shipping_estimator' && MODULE_SHIPPING_USPS_DEBUG_MODE != 'Off') {
                    echo '<br />USPS INTERNATIONAL $request: <br />' . 'API=IntlRateV2&XML=' . $this->request_display . '<br />';
                }
                // prepare request for debug log
                $this->request_display = $request;
            }
            $request = 'API=IntlRateV2&XML=' . urlencode($request);
        }

        // Prepare to make quote-request to USPS servers

        switch (MODULE_SHIPPING_USPS_SERVER) {
            case 'production':
                $usps_server = 'http://production.shippingapis.com';
                $api_dll = 'shippingapi.dll';
                break;
            case 'test':
            default:
    // 09-7-2014
    //Secure APIs: https://stg-secure.shippingapis.com/ShippingApi.dll
    //Non-secure APIs: http://stg-production.shippingapis.com/ShippingApi.dll
                $usps_server = 'http://stg-production.shippingapis.com';
                $api_dll = 'ShippingApi.dll';
                break;
        }

        $body = '';
        // BOF CURL
        // Send quote request via CURL
        global $request_type;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $usps_server . '/' . $api_dll);
        curl_setopt($ch, CURLOPT_REFERER, ($request_type == 'SSL' ? HTTPS_SERVER . DIR_WS_HTTPS_CATALOG : HTTP_SERVER . DIR_WS_CATALOG ));
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    //    curl_setopt($ch, CURLOPT_SSLVERSION, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Zen Cart');
        if (CURL_PROXY_REQUIRED == 'True') {
            $this->proxy_tunnel_flag = (defined('CURL_PROXY_TUNNEL_FLAG') && strtoupper(CURL_PROXY_TUNNEL_FLAG) == 'FALSE') ? false : true;
            curl_setopt ($ch, CURLOPT_HTTPPROXYTUNNEL, $this->proxy_tunnel_flag);
            curl_setopt ($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            curl_setopt ($ch, CURLOPT_PROXY, CURL_PROXY_SERVER_DETAILS);
        }

        // submit request
        // set to TRUE to show times when debug is enabled
        $show_times = false;
        if ($show_times && MODULE_SHIPPING_USPS_DEBUG_MODE != 'Off') {
            echo 'Time sent to USPS before curl_exec: ' . date('M d Y G:i:s') . ' ' . time() . '<br>';
        }

        $body = curl_exec($ch);
//echo '$body: ' . $body . '<br>';
        $this->commError = curl_error($ch);
        $this->commErrNo = curl_errno($ch);
        $this->commInfo = @curl_getinfo($ch);
// error_log($this->commInfo . ' ' . time() . ' - microtime: ' . microtime());

        // SUBMIT ADDITIONAL REQUESTS FOR DELIVERY TIME ESTIMATES
        if ($this->transitTimeCalculationMode == 'OLD' && $this->getTransitTime && count($transreq) != 0) {
            $transitResp = array();
            foreach ($transreq as $key => $value) {
                $transitResp[$key] = '';
                if ($value != '') {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $value);
                    $transitResp[$key] = curl_exec($ch);
                }
            }
            $this->parseDomesticLegacyAPITransitTimeResults($transitResp);
        }

        // done with CURL, so close connection
        curl_close ($ch);
        if ($show_times && MODULE_SHIPPING_USPS_DEBUG_MODE != 'Off') {
            echo 'Time sent to USPS after curl_exec: ' . date('M d Y G:i:s') . ' ' . time() . '<br><br>';
        }

        // DEV ONLY - dump out the returned data for debugging
        if (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Email') {
            mail(STORE_OWNER_EMAIL_ADDRESS, 'Debug: USPS rate quote response', '(You can turn off this debug email by editing your USPS module settings in the admin area of your store.) ' . "\n\n" . $body, 'From: <' . EMAIL_FROM . '>');
        }
        //      echo 'USPS METHODS: <pre>'; echo print_r($body); echo '</pre>';

        if (MODULE_SHIPPING_USPS_DEBUG_MODE != 'Off') {
            global $shipping_weight, $currencies;
            $body_display_header = '';
            $body_display_header .= "\n" . 'USPS build: ' . MODULE_SHIPPING_USPS_VERSION . "\n\n";
            $body_display_header .= 'Server: ' . MODULE_SHIPPING_USPS_SERVER . "\n";
            $body_display_header .= 'Quote Request Rate Type: ' . MODULE_SHIPPING_USPS_RATE_TYPE . "\n";
            $body_display_header .= 'Quote from main_page: ' . $_GET['main_page']. "\n";
            $body_display_header .= 'USPS Options (weight, time): ' . MODULE_SHIPPING_USPS_OPTIONS . "\n";
            $body_display_header .= 'USPS Domestic Transit Time Calculation Mode: ' . MODULE_SHIPPING_USPS_TRANSIT_TIME_CALCULATION_MODE . "\n";

            $body_display_header .= "\n" . 'Cart Weight: ' . $_SESSION['cart']->weight . "\n";
            $body_display_header .= 'Total Quote Weight: ' . $shipping_weight . ' Pounds: ' . $this->pounds . ' Ounces: ' . $this->ounces . "\n";
            $body_display_header .= 'Maximum: ' . SHIPPING_MAX_WEIGHT . ' Tare Rates: Small/Medium: ' . SHIPPING_BOX_WEIGHT . ' Large: ' . SHIPPING_BOX_PADDING . "\n";
            $body_display_header .= 'Handling method: ' . MODULE_SHIPPING_USPS_HANDLING_METHOD . ' Handling fee Domestic: ' . $currencies->format(MODULE_SHIPPING_USPS_HANDLING) . ' Handling fee International: ' . $currencies->format(MODULE_SHIPPING_USPS_HANDLING_INT) . "\n";

            $body_display_header .= 'Decimals: ' . MODULE_SHIPPING_USPS_DECIMALS . "\n";
            $body_display_header .= 'Domestic Length: ' . MODULE_SHIPPING_USPS_LENGTH . ' Width: ' . MODULE_SHIPPING_USPS_WIDTH . ' Height: ' . MODULE_SHIPPING_USPS_HEIGHT . "\n";
            $body_display_header .= 'International Length: ' . MODULE_SHIPPING_USPS_LENGTH_INTL . ' Width: ' . MODULE_SHIPPING_USPS_WIDTH_INTL . ' Height: ' . MODULE_SHIPPING_USPS_HEIGHT_INTL . "\n";

            $body_display_header .= 'All Packages are Machinable: ' . MODULE_SHIPPING_USPS_MACHINABLE . "\n";
            $body_display_header .= 'Enable USPS First-Class filter for US shipping: ' . MODULE_SHIPPING_USPS_FIRST_CLASS_FILTER_US . "\n";
            $body_display_header .= 'Sorts the returned quotes: ' . MODULE_SHIPPING_USPS_QUOTE_SORT . "\n";

            $body_display_header .= "\n" . 'ZipOrigination: ' . ((int)SHIPPING_ORIGIN_ZIP == 0 ? '***WARNING: NO STORE 5 DIGIT ZIP CODE SET' : SHIPPING_ORIGIN_ZIP) . "\n" . 'ZipDestination: ' . $order->delivery['postcode'] . (!empty($this->countries[$order->delivery['country']['iso_code_2']]) ? ' Country: ' . $this->countries[$order->delivery['country']['iso_code_2']] : '') . ($order->delivery['city'] != '' ? ' City: ' . $order->delivery['city'] : '') . ($order->delivery['state'] != '' ? ' State: ' . $order->delivery['state'] : '') . "\n";

            $body_display_header .= 'Order SubTotal: ' . $currencies->format($order->info['subtotal']) . "\n";
            $body_display_header .= 'Order Total: ' . $currencies->format($shipment_value) . "\n";
            $body_display_header .= 'Uninsurable Portion: ' . $currencies->format($uninsurable_value) . "\n";
            $body_display_header .= 'Insurable Value: ' . $currencies->format($insurable_value) . "\n";
            $body_display_header .= "\n" . 'RESPONSE FROM USPS: ' . "\n";
            $body_display_header .= "\n" . '==================================' . "\n";

            // build list of requested shipping services
            $services_domestic = 'Domestic Services Selected: ' . "\n";
            $services_international = 'International Services Selected: ' . "\n";
            // Domestic/US destination:
            if ($this->is_us_shipment) {
                $dOptions = explode(', ', MODULE_SHIPPING_USPS_DMST_SERVICES); // domestic
            //echo 'USPS $dOptions: ' . '<pre>'; echo var_dump($dOptions); echo '</pre>' . '<br>';
                foreach ($dOptions as $key => $val) {
                    if (strlen($dOptions[$key]) > 1) {
                        if ($dOptions[$key+1] == 'C' || $dOptions[$key+1] == 'S' || $dOptions[$key+1] == 'Y') {
                            $services_domestic .= $dOptions[$key] . "\n";
                        }
            //echo '$dOptions[$key]: > 1 ' . $dOptions[$key] . ' $dOptions[$key+1]: ' . $dOptions[$key+1] . '<br>';
                    }
                }
            } else {
                // International destination:
                $iOptions = explode(', ', MODULE_SHIPPING_USPS_INTL_SERVICES);
                foreach ($iOptions as $key => $val) {
                    if(strlen($iOptions[$key]) > 1) {
                        if ($iOptions[$key+1] == 'C' || $iOptions[$key+1] == 'S' || $iOptions[$key+1] == 'Y') {
                            $services_international .= $iOptions[$key] . "\n";
                        }
          //echo '$iOptions[$key]: > 1 ' . $iOptions[$key] . ' $iOptions[$key+1]: ' . $iOptions[$key+1] . '<br>';
                    }
                }
            }
            if ($this->is_us_shipment) {
                $usps_shipping_services_selected = $services_domestic;
            } else {
                $usps_shipping_services_selected = $services_international;
            }

            $usps_shipping_country  = str_replace("<br />", "\n", $usps_shipping_country);
            $usps_shipping_methods_domestic = str_replace("<br />", "\n", $usps_shipping_methods_domestic);
            $usps_shipping_methods_international = str_replace("<br />", "\n", $usps_shipping_methods_international);
            if ($this->is_us_shipment) {
                $usps_shipping_methods_selected = $usps_shipping_methods_domestic . "\n\n" . $package_id . "\n\n";
            } else {
                $usps_shipping_methods_selected = $usps_shipping_methods_international . "\n\n";
            }
        }

        if (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Screen') {
            echo ($this->commErrNo != 0 ? '<br />' . $this->commErrNo . ' ' . $this->commError : '') . '<br /><pre>' . $body . '</pre><br />';
        }

        if (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Logs') {
            // skip debug log if no destination zipcode is set:   0==(int)SHIPPING_ORIGIN_ZIP
            $fp = @fopen($logfilename, 'a');
            if ($fp && $this->commErrNo != 0) {
                fwrite($fp, date('M d Y G:i:s') .
                    ' -- ' . 'CommErr (should be 0): ' . $this->commErrNo . ' - ' . $this->commError . "\n\n\n\n" .
                    $body_display_header . "\n\n" .
                    $usps_shipping_country .
                    $usps_shipping_methods_selected .
                    '==================================' . "\n\n" .
                    $usps_shipping_services_selected . "\n" .
                    '==================================' . "\n\n" .
                    'SENT TO USPS:' . "\n\n"
                );
                fclose($fp);
            }
        }
        //if communication error, return -1 because no quotes were found, and user doesn't need to see the actual error message (set DEBUG mode to get the messages logged instead)
        if ($this->commErrNo != 0) {
            return -1;
        }
        // EOF CURL

        // USPS debug to Logs
        if (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Logs' || MODULE_SHIPPING_USPS_DEBUG_MODE == 'Screen') {
//echo '<BR><BR>ORIGINAL $body: ' . $body . '<br><br>';
            $body_display = str_replace('&amp;lt;sup&amp;gt;&amp;#8482;&amp;lt;/sup&amp;gt;', 'TM', $body);
//echo '<BR><BR>CLEAN A TM: $body_display: ' . $body_display . '<br>';
            $body_display = str_replace('&amp;lt;sup&amp;gt;&amp;#174;&amp;lt;/sup&amp;gt;', 'RM', $body_display);
//echo '<BR><BR>CLEAN B RM: $body_display: ' . $body_display . '<br>';
            $body_display = str_replace('<Service ID', (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Logs' ? "\n\n" : '<br /><br />') . '<Service ID', $body_display);
            $body_display = str_replace('</Service>', '</Service>' . "\n", $body_display);

            $body_display = str_replace('<SvcDescription', (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Logs' ? "\n" : '<br />') . '<SvcDescription', $body_display);

            $body_display = str_replace('<MaxDimensions>', "\n" . '<MaxDimensions>', $body_display);
            $body_display = str_replace('<MaxWeight>', "\n" . '<MaxWeight>', $body_display);

            $body_display = str_replace('<Package ID', (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Logs' ? "\n\n\n" : '<br /><br /><br />') . '<Package ID', $body_display);

            $body_display = str_replace('<Postage CLASSID', "\n" . '<Postage CLASSID', $body_display);

            $body_display = str_replace('<Rate>', (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Logs' ? "\n" : '<br />') . '<Rate>', $body_display);
            $body_display = str_replace('<SpecialServices', (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Logs' ? "\n" : '<br />') . '<SpecialServices', $body_display);

            $body_display = str_replace('<ServiceID>', "\n" . '<ServiceID>', $body_display);
            $body_display = str_replace('<Description>', "\n" . '<Description>', $body_display);

//echo 'USPS $this->usps_countries: ' . $this->usps_countries . '<br>';
            if ($this->is_us_shipment) {
                $body_display = str_replace('</Postage>', "\n" . '</Postage>', $body_display);
                $body_display = str_replace('<Location>', "\n\t\t\t" .'<Location>', $body_display);
                $body_display = str_replace('</RateV4Response>', "\n" . '</RateV4Response>', $body_display);
            }
            if (!$this->is_us_shipment) {
                $body_display = str_replace('<Postage>', (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Logs' ? "\n" : '<br />') . '<Postage>', $body_display);
                $body_display = str_replace('<ValueOfContents>', "\n" . '<ValueOfContents>', $body_display);
            }


            if (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Screen') {
                echo '<br />View Source:<br />' . "\n" . $body_display_header . "\n\n" . $body_display . '<br />';
            }
            if (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Logs') {
                // skip debug log if no destination zipcode is set
                $fp = @fopen($logfilename, 'a');
                if ($fp) {
                    $this->request_display = preg_replace(array('/></', '/>  </'), array('>' . "\n". '<', '>' . "\n" . ' <'), htmlspecialchars_decode($this->request_display));
                    fwrite($fp,
                        date('M d Y G:i:s') . ' -- ' .
                        $body_display_header . "\n\n" .
                        $body_display . "\n\n" .
                        $usps_shipping_country .
                        $usps_shipping_methods_selected .
                        '==================================' . "\n\n" .
                        $usps_shipping_services_selected . "\n" .
                        '==================================' . "\n\n" .
                        'SENT TO USPS:' . "\n\n" .
                        $this->request_display . "\n\n" .
                        "============\n\nRAW XML FROM USPS:\n\n" . print_r(simplexml_load_string($body), true) . "\n\n"
                    );
                    fclose($fp);
                }
            }
        }

//      echo 'USPS METHODS: <pre>'; echo print_r($body); echo '</pre>';


    // This occasionally threw an error with simplexml; may only be needed for the test server but could change in the future for the production server
    /* $body = str_replace('<?xml version="1.0" encoding="UTF-8"?>', '<?xml version="1.0"?>', $body);
     */
        $this->notify('NOTIFY_SHIPPING_USPS_QUOTES_RECEIVED');
        $body_array = simplexml_load_string($body);
        $body_encoded = json_decode(json_encode($body_array), true);
        return $body_encoded;
    }

    /**
     * Legacy method:
     * Parse the domestic-services transit time results data obtained from special extra API calls
     * @param array $transresp
     */
    protected function parseDomesticLegacyAPITransitTimeResults($transresp) 
    {
        global $logfilename;
        if (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Logs') {
            $fp = @fopen($logfilename, 'a');
            fwrite($fp, date('M d Y G:i:s') . ' -- TRANSIT TIME PARSING (domestic legacy API)' . "\n\n");
        }
        foreach ($transresp as $service => $val) {
            $val = json_decode(json_encode(simplexml_load_string($val)), true);
            switch (true) {
                case (preg_match('#Priority Mail Express#i', $service)):
                    $time = $val['CommitmentTime'];
                    if ($time == '' || $time == 'No Data') {
                        $time = '1 - 2 ' . MODULE_SHIPPING_USPS_TEXT_DAYS;
                    } else {
                        $time = 'Tomorrow by ' . $time;
                    }
                    break;
                case (preg_match('#Priority MailTM#i', $service)):
                    $time = $val['Days'];
                    if ($time == '' || $time == 'No Data') {
                        $time = '2 - 3 ' . MODULE_SHIPPING_USPS_TEXT_DAYS;
                    } elseif ($time == '1') {
                        $time .= ' ' . MODULE_SHIPPING_USPS_TEXT_DAY;
                    } else {
                        $time .= ' ' . MODULE_SHIPPING_USPS_TEXT_DAYS;
                    }
                    break;
                case (preg_match('#USPS Retail Ground#i', $service)):
                    $time = $val['Days'];
                    if ($time == '' || $time == 'No Data') {
                        $time = '4 - 7 ' . MODULE_SHIPPING_USPS_TEXT_DAYS;
                    } elseif ($time == '1') {
                        $time .= ' ' . MODULE_SHIPPING_USPS_TEXT_DAY;
                    } else {
                        $time .= ' ' . MODULE_SHIPPING_USPS_TEXT_DAYS;
                    }
                    break;
                case (preg_match('#First\-Class#i', $service)):
                    $time = '2 - 5 ' . MODULE_SHIPPING_USPS_TEXT_DAYS;
                    break;
                case (preg_match('#Media Mail Parcel#i', $service)):
                default:
                    $time = '';
                    break;
            }
            $this->transittime[$service] = $time == '' ? '' : ' (' . $time . ')';
    //echo 'USPS parseDomesticLegacyAPITransitTimeResults $service: ' . $service . ' $val[CommitmentTime]: ' . ($val['CommitmentTime'] == '' ? '***' : $val['CommitmentTime']) . ' $val[Days]: ' . ($val['Days'] == '' ? '***' : $val['Days']) . ' $time: ' . ($time == '' ? '***' : $time) . ' FINAL: $this->transittime[$service]: ' . $this->transittime[$service] . '<br>';
            // do logging if the file was opened earlier by the config switch
            if ($fp) {
                fwrite($fp, date('M d Y G:i:s') . ' -- Transit Time' . "\nService" . $service . "\nCommitmentTime (from USPS): " . $val['CommitmentTime'] . "\nDays(from USPS): " . $val['Days'] . "\n" . '$time (calculated): ' . $time . "\nTranslation:" . $this->transittime[$service] . "\n\n");
            }
        }
        // close log file if opened
        if ($fp) {
            fclose($fp);
        }
    }

    /**
     * Parse the domestic-services transit time results data returned by passing the <ShipDate> request parameter
     * @param array $Package - The package details array to parse, received from USPS and semi-sanitized already
     * @param string $service - The delivery service being evaluated
     * ref: <CommitmentDate>2013-07-23</CommitmentDate><CommitmentName>1-Day</CommitmentName>
     */
    protected function parseDomesticTransitTimeResults($Package, $service) 
    {
        global $logfilename;
        if (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Logs') {
          $fp = @fopen($logfilename, 'a');
        }
        $time = isset($Package['CommitmentName']) ? $Package['CommitmentName'] : '';
        if ($time == '' || $this->transitTimeCalculationMode == 'CUSTOM') {
            switch (true) {
      /********************* CUSTOM START:  IF YOU HAVE CUSTOM TRANSIT TIMES ENTER THEM HERE ***************/
                case (preg_match('#Priority Mail Express#i', $service)):
                    $time = '1 - 2 ' . MODULE_SHIPPING_USPS_TEXT_DAYS;
                    break;
                case (preg_match('#Priority MailTM#i', $service)):
                    $time = '2 - 3 ' . MODULE_SHIPPING_USPS_TEXT_DAYS;
                    break;
                case (preg_match('#USPS Retail GroundRM#i', $service)):
                    $time = '4 - 7 ' . MODULE_SHIPPING_USPS_TEXT_DAYS;
                break;
                case (preg_match('#First\-Class#i', $service)):
                    $time = '2 - 5 ' . MODULE_SHIPPING_USPS_TEXT_DAYS;
                    break;
                case (preg_match('#Media Mail Parcel#i', $service)):
                default:
                    $time = '';
                    break;
      /********************* CUSTOM END:  IF YOU HAVE CUSTOM TRANSIT TIMES ENTER THEM HERE ***************/
            }
        } else {
            // fix USPS issues with CommitmentName, example: GUAM
            if (is_array($time)) {
                $time = '';
            } else {
                $time = preg_replace('/Weeks$/', MODULE_SHIPPING_USPS_TEXT_WEEKS, $time);
                $time = preg_replace('/Days$/', MODULE_SHIPPING_USPS_TEXT_DAYS, $time);
                $time = preg_replace('/Day$/', MODULE_SHIPPING_USPS_TEXT_DAY, $time);
            }
        }

        // bof: not guaranteed on NOT Priority Mail Express
        // remove comment // marks to use
        if (!preg_match('#Priority Mail Express#i', $service)) {
        //  $time .= ' - not guaranteed Domestic';
        }
        // eof: not guaranteed on NOT Priority Mail Express

        $this->transittime[$service] = $time == '' ? '' : ' (' . $time . ')';
//echo 'USPS parseDomesticTransitTimeResults $service: ' . $service . ' $Package[CommitmentName]: ' . ($Package['CommitmentName'] == '' ? '***' : $Package['CommitmentName']) . ' $val[Days]: ' . ($val['Days'] == '' ? '***' : $val['Days']) . ' $time: ' . ($time == '' ? '***' : $time) . ' FINAL: $this->transittime[$service]: ' . $this->transittime[$service] . '<br>';
        // do logging if the file was opened earlier by the config switch
        if ($fp) {
            fwrite($fp, date('M d Y G:i:s') . ' -- Transit Time (Domestic)' . "\nService:                    " . $service . "\nCommitmentName (from USPS): " . $Package['CommitmentName'] . "\n" . '$time (calculated):         ' . $time . "\nTranslation:               " . $this->transittime[$service] . "\n\n");
        }
        // close log file if opened
        if ($fp) {
            fclose($fp);
        }
    }

    /**
     * Parse the international-services transit time results data
     * Parse the domestic-services transit time results data returned by passing the <ShipDate> request parameter
     * @param array $Package - The package details array to parse, received from USPS and semi-sanitized already
     * @param string $service - The delivery service being evaluated
     * ref: <SvcCommitments>value</SvcCommitments>
     */
    protected function parseIntlTransitTimeResults($Package, $service) 
    {
        global $logfilename;
        if (MODULE_SHIPPING_USPS_DEBUG_MODE == 'Logs') {
            $fp = @fopen($logfilename, 'a');
        }
        if (!preg_match('#(GXG|International)#i', $service)) {
            if ($fp) {
                fwrite($fp, date('M d Y G:i:s') . ' -- Transit Time (Intl)' . "\nService:                    " . $service . "\nWARNING: NOT INTERNATIONAL. SKIPPING.\n\n");
                fclose($fp);
            }
            return;
        }

        $time = isset($Package['SvcCommitments']) ? $Package['SvcCommitments'] : '';
        if ($time == '' || $this->transitTimeCalculationMode == 'CUSTOM') {
            switch (true) {
            /********************* CUSTOM START:  IF YOU HAVE CUSTOM TRANSIT TIMES ENTER THEM HERE ***************/
                case (preg_match('#Priority Mail Express#i', $service)):
                    $time = '3 - 5 business ' . MODULE_SHIPPING_USPS_TEXT_DAYS;
                    break;
                case (preg_match('#Priority Mail#i', $service)):
                    $time = '6 - 10 business ' . MODULE_SHIPPING_USPS_TEXT_DAYS;
                    break;
                case (preg_match('#Global Express Guaranteed#i', $service)):
                    $time = '1 - 3 business ' . MODULE_SHIPPING_USPS_TEXT_DAYS;
                    break;
                case (preg_match('#USPS GXG.* Envelopes#i', $service)):
                    $time = '1 - 3 business ' . MODULE_SHIPPING_USPS_TEXT_DAYS;
                    break;
                case (preg_match('#First\-Class#i', $service)):
                    $time = 'Varies by destination'; // '' . MODULE_SHIPPING_USPS_TEXT_DAYS;
                    break;
                default:
                    $time = '';
                    break;
            /********************* CUSTOM END:  IF YOU HAVE CUSTOM TRANSIT TIMES ENTER THEM HERE ***************/
            }
        } else {
            $time = preg_replace('/Weeks$/', MODULE_SHIPPING_USPS_TEXT_WEEKS, $time);
            $time = preg_replace('/Days$/', MODULE_SHIPPING_USPS_TEXT_DAYS, $time);
            $time = preg_replace('/Day$/', MODULE_SHIPPING_USPS_TEXT_DAY, $time);
        }

        // uncomment to remove extra text in times
        //    $time = str_replace(' to many major markets', '', $time);

        // bof: not guaranteed on NOT Priority Mail Express
        // remove comment // marks to use
        if (!preg_match('#Priority Mail Express#i', $service)) {
        //  $time .= ' - not guaranteed International';
        }
        // eof: not guaranteed on NOT Priority Mail Express

        $this->transittime[$service] = $time == '' ? '' : ' (' . $time . ')';
    //echo 'USPS parseIntlTransitTimeResults $service: ' . $service . ' $Package[SvcCommitments]: ' . ($Package['SvcCommitments'] == '' ? '***' : $Package['SvcCommitments']) . ' $time: ' . ($time == '' ? '***' : $time) . ' FINAL: $this->transittime[$service]: ' . $this->transittime[$service] . '<br>';
        // do logging if the file was opened earlier by the config switch
        if ($fp) {
            fwrite($fp, date('M d Y G:i:s') . ' -- Transit Time (Intl)' . "\nService:                    " . $service . "\nSvcCommitments (from USPS): " . $Package['SvcCommitments'] . "\n" . '$time (calculated):         ' . $time . "\nTranslation:               " . $this->transittime[$service] . "\n\n");
            fclose($fp);
        }
    }

    /**
     * USPS Country Code List
     * This list is used to compare the 2-letter ISO code against the order country ISO code, and provide the proper/expected
     * spelling of the country name to USPS in order to obtain a rate quote
     *
     * @return array
     */
    protected function country_list() 
    {
        $list = array(
            'AF' => 'Afghanistan',
            'AL' => 'Albania',
            'AX' => 'Aland Island (Finland)',
            'DZ' => 'Algeria',
            'AD' => 'Andorra',
            'AO' => 'Angola',
            'AI' => 'Anguilla',
            'AG' => 'Antigua and Barbuda',
            'AR' => 'Argentina',
            'AM' => 'Armenia',
            'AW' => 'Aruba',
            'AU' => 'Australia',
            'AT' => 'Austria',
            'AZ' => 'Azerbaijan',
            'BS' => 'Bahamas',
            'BH' => 'Bahrain',
            'BD' => 'Bangladesh',
            'BB' => 'Barbados',
            'BY' => 'Belarus',
            'BE' => 'Belgium',
            'BZ' => 'Belize',
            'BJ' => 'Benin',
            'BM' => 'Bermuda',
            'BT' => 'Bhutan',
            'BO' => 'Bolivia',
            'BQ' => 'Bonaire (Curacao)',
            'BA' => 'Bosnia-Herzegovina',
            'BW' => 'Botswana',
            'BR' => 'Brazil',
            'VG' => 'British Virgin Islands',
            'BN' => 'Brunei Darussalam',
            'BG' => 'Bulgaria',
            'BF' => 'Burkina Faso',
            'MM' => 'Burma',
            'BI' => 'Burundi',
            'KH' => 'Cambodia',
            'CM' => 'Cameroon',
            'CA' => 'Canada',
            'CV' => 'Cape Verde',
            'KY' => 'Cayman Islands',
            'CF' => 'Central African Republic',
            'TD' => 'Chad',
            'CL' => 'Chile',
            'CN' => 'China',
            'CX' => 'Christmas Island (Australia)',
            'CC' => 'Cocos Island (Australia)',
            'CO' => 'Colombia',
            'KM' => 'Comoros',
            'CG' => 'Congo, Republic of the',
            'CD' => 'Congo, Democratic Republic of the',
            'CK' => 'Cook Islands (New Zealand)',
            'CR' => 'Costa Rica',
            'CI' => 'Cote d Ivoire (Ivory Coast)',
            'HR' => 'Croatia',
            'CU' => 'Cuba',
            'CW' => 'Curacao',
            'CY' => 'Cyprus',
            'CZ' => 'Czech Republic',
            'DK' => 'Denmark',
            'DJ' => 'Djibouti',
            'DM' => 'Dominica',
            'DO' => 'Dominican Republic',
            'EC' => 'Ecuador',
            'EG' => 'Egypt',
            'SV' => 'El Salvador',
            'GQ' => 'Equatorial Guinea',
            'ER' => 'Eritrea',
            'EE' => 'Estonia',
            'ET' => 'Ethiopia',
            'FK' => 'Falkland Islands',
            'FO' => 'Faroe Islands',
            'FJ' => 'Fiji',
            'FI' => 'Finland',
            'FR' => 'France',
            'GF' => 'French Guiana',
            'PF' => 'French Polynesia',
            'GA' => 'Gabon',
            'GM' => 'Gambia',
            'GE' => 'Georgia, Republic of',
            'DE' => 'Germany',
            'GH' => 'Ghana',
            'GI' => 'Gibraltar',
            'GB' => 'Great Britain and Northern Ireland',
            'GR' => 'Greece',
            'GL' => 'Greenland',
            'GD' => 'Grenada',
            'GP' => 'Guadeloupe',
            'GT' => 'Guatemala',
            'GN' => 'Guinea',
            'GW' => 'Guinea-Bissau',
            'GY' => 'Guyana',
            'HT' => 'Haiti',
            'HN' => 'Honduras',
            'HK' => 'Hong Kong',
            'HU' => 'Hungary',
            'IS' => 'Iceland',
            'IN' => 'India',
            'ID' => 'Indonesia',
            'IR' => 'Iran',
            'IQ' => 'Iraq',
            'IE' => 'Ireland',
            'IL' => 'Israel',
            'IT' => 'Italy',
            'JM' => 'Jamaica',
            'JP' => 'Japan',
            'JO' => 'Jordan',
            'KZ' => 'Kazakhstan',
            'KE' => 'Kenya',
            'KI' => 'Kiribati',
            'KW' => 'Kuwait',
            'KG' => 'Kyrgyzstan',
            'LA' => 'Laos',
            'LV' => 'Latvia',
            'LB' => 'Lebanon',
            'LS' => 'Lesotho',
            'LR' => 'Liberia',
            'LY' => 'Libya',
            'LI' => 'Liechtenstein',
            'LT' => 'Lithuania',
            'LU' => 'Luxembourg',
            'MO' => 'Macao',
            'MK' => 'Macedonia, Republic of',
            'MG' => 'Madagascar',
            'MW' => 'Malawi',
            'MY' => 'Malaysia',
            'MV' => 'Maldives',
            'ML' => 'Mali',
            'MT' => 'Malta',
            'MQ' => 'Martinique',
            'MR' => 'Mauritania',
            'MU' => 'Mauritius',
            'YT' => 'Mayotte (France)',
            'MX' => 'Mexico',
            'FM' => 'Micronesia, Federated States of',
            'MD' => 'Moldova',
            'MC' => 'Monaco (France)',
            'MN' => 'Mongolia',
            'MS' => 'Montserrat',
            'MA' => 'Morocco',
            'MZ' => 'Mozambique',
            'NA' => 'Namibia',
            'NR' => 'Nauru',
            'NP' => 'Nepal',
            'NL' => 'Netherlands',
            'AN' => 'Netherlands Antilles',
            'NC' => 'New Caledonia',
            'NZ' => 'New Zealand',
            'NI' => 'Nicaragua',
            'NE' => 'Niger',
            'NG' => 'Nigeria',
            'KP' => 'North Korea (Korea, Democratic People\'s Republic of)',
            'NO' => 'Norway',
            'OM' => 'Oman',
            'PK' => 'Pakistan',
            'PA' => 'Panama',
            'PG' => 'Papua New Guinea',
            'PY' => 'Paraguay',
            'PE' => 'Peru',
            'PH' => 'Philippines',
            'PN' => 'Pitcairn Island',
            'PL' => 'Poland',
            'PT' => 'Portugal',
            'QA' => 'Qatar',
            'RE' => 'Reunion',
            'RO' => 'Romania',
            'RU' => 'Russia',
            'RW' => 'Rwanda',
            'SH' => 'Saint Helena',
            'KN' => 'Saint Kitts (Saint Christopher and Nevis)',
            'LC' => 'Saint Lucia',
            'PM' => 'Saint Pierre and Miquelon',
            'VC' => 'Saint Vincent and the Grenadines',
            'SM' => 'San Marino',
            'ST' => 'Sao Tome and Principe',
            'SA' => 'Saudi Arabia',
            'SN' => 'Senegal',
            'RS' => 'Serbia',
            'SC' => 'Seychelles',
            'SL' => 'Sierra Leone',
            'SG' => 'Singapore',
            'SX' => 'Sint Maarten (Dutch)',
            'SK' => 'Slovak Republic',
            'SI' => 'Slovenia',
            'SB' => 'Solomon Islands',
            'SO' => 'Somalia',
            'ZA' => 'South Africa',
            'GS' => 'South Georgia (Falkland Islands)',
            'KR' => 'South Korea (Korea, Republic of)',
            'ES' => 'Spain',
            'LK' => 'Sri Lanka',
            'SD' => 'Sudan',
            'SR' => 'Suriname',
            'SZ' => 'Swaziland',
            'SE' => 'Sweden',
            'CH' => 'Switzerland',
            'SY' => 'Syrian Arab Republic',
            'TW' => 'Taiwan',
            'TJ' => 'Tajikistan',
            'TZ' => 'Tanzania',
            'TH' => 'Thailand',
            'TL' => 'East Timor (Indonesia)',
            'TG' => 'Togo',
            'TK' => 'Tokelau (Union Group) (Western Samoa)',
            'TO' => 'Tonga',
            'TT' => 'Trinidad and Tobago',
            'TN' => 'Tunisia',
            'TR' => 'Turkey',
            'TM' => 'Turkmenistan',
            'TC' => 'Turks and Caicos Islands',
            'TV' => 'Tuvalu',
            'UG' => 'Uganda',
            'UA' => 'Ukraine',
            'AE' => 'United Arab Emirates',
            'UY' => 'Uruguay',
            'UZ' => 'Uzbekistan',
            'VU' => 'Vanuatu',
            'VA' => 'Vatican City',
            'VE' => 'Venezuela',
            'VN' => 'Vietnam',
            'WF' => 'Wallis and Futuna Islands',
            'WS' => 'Western Samoa',
            'YE' => 'Yemen',
            'ZM' => 'Zambia',
            'ZW' => 'Zimbabwe',
            'PS' => 'Palestinian Territory', // usps does not ship
            'ME' => 'Montenegro',
            'GG' => 'Guernsey',
            'IM' => 'Isle of Man',
            'JE' => 'Jersey'
        );
        $this->notify('NOTIFY_SHIPPING_USPS_COUNTRY_LIST', array(), $list);
        return $list;
    }

// translate for US Territories
    protected function usps_translation() 
    {
        $this->notify('NOTIFY_SHIPPING_USPS_TRANSLATION');
        global $order;
        $delivery_country = 'US';
        if (SHIPPING_ORIGIN_COUNTRY == '223') {
            switch ($order->delivery['country']['iso_code_2']) {
                case 'AS': // Samoa American
                case 'GU': // Guam
                case 'MP': // Northern Mariana Islands
                case 'PW': // Palau
                case 'PR': // Puerto Rico
                case 'VI': // Virgin Islands US
    // which is right
                case 'FM': // Micronesia, Federated States of
                    break;
    // stays as original country
    //        case 'FM': // Micronesia, Federated States of
                default:
                    $delivery_country = $order->delivery['country']['iso_code_2'];
                    break;
            }
        } else {
            $delivery_country = $order->delivery['country']['iso_code_2'];
        }
        
        // -----
        // If the delivery country is the US, set a multi-use processing flag
        // to simplify the remaining code.
        //
        $this->is_us_shipment = ($delivery_country == 'US');
        
        return $delivery_country;
    }

    // used for shipDate
    protected function zen_usps_shipdate() 
    {
        // safety calculation for cutoff time
        if ($this->shipping_cutoff_time < 1200 || $this->shipping_cutoff_time > 2300) {
            $this->shipping_cutoff_time = 1400;
        }
        // calculate today vs tomorrow based on time
        if (version_compare(PHP_VERSION, 5.2, '>=')) {
            if (date('Gi') < preg_replace('/[^\d]/', '', $this->shipping_cutoff_time)) { // expects it in the form of HHMM
                $datetime = new DateTime('today');
            } else {
                $datetime = new DateTime('tomorrow');
            //         $datetime = new DateTime((date('l') == 'Friday') ? 'Monday next week' : 'tomorrow');
            }
            $usps_date = $datetime->format('Y-m-d');
        } else {
            // old PHP versions use just today's date:
            $usps_date = date('Y-m-d');
        }
        // echo 'USPS DATE ' . $usps_date . '<br>';
        return $usps_date;
    }

    protected function clean_usps_marks($string) 
    {
        // strip reg and trade symbols
        $string = str_replace(array('&amp;lt;sup&amp;gt;&amp;#174;&amp;lt;/sup&amp;gt;', '&amp;lt;sup&amp;gt;&amp;#8482;&amp;lt;/sup&amp;gt;'), array('RM', 'TM'), htmlspecialchars($string));
        //echo '$string AFTER: ' . $string . '<br>';

        // shipdate info removed from names as it is contained in the shipping methods
        // refers to this field for Domestic: <CommitmentName>  or International: <SvcCommitments>
        $string = str_replace(array('Mail 1-Day', 'Mail 2-Day', 'Mail 3-Day', 'Mail Military', 'Mail DPO'), 'Mail', $string);
        $string = str_replace(array('Express 1-Day', 'Express 2-Day', 'Express 3-Day', 'Express Military', 'Express DPO'), 'Express', $string);

        return $string;
    }

    // return SpecialService tags based on checked choices only
    protected function special_services() 
    {
/*
The Special service definitions are as follows:
USPS Special Service Name ServiceID - Our Special Service Name
  Certified 0 - Certified MailRM
  Insurance 1 - Insurance
Restricted Delivery 3
  Registered without Insurance 4 - Registered without Insurance - REMOVED
  Registered with Insurance 5 - Registered MailTM
  Collect on Delivery 6 - Collect on Delivery
  Return Receipt for Merchandise 7 - Return Receipt for Merchandise
  Return Receipt 8 - Return Receipt
  Certificate of Mailing (Form 3817) (per individual article) 9 - Certificate of Mailing (Form 3817)
Certificate of Mailing (for firm mailing books) 10
  Express Mail Insurance 11 - Priority Mail Express Insurance
  USPS Tracking/Delivery Confirmation 13 - USPS TrackingTM
  USPS TrackingTM Electronic 12 - USPS TrackingTM Electronic
  Signature Confirmation 15 - Signature ConfirmationTM
  Signature ConfirmationTM Electronic 14 - Signature ConfirmationTM Electronic
Return Receipt Electronic 16
  Adult Signature Required 19 - Adult Signature Required
  Adult Signature Restricted Delivery 20 - Adult Signature Restricted Delivery
Priority Mail Express 1030 AM Delivery 200 - Priority Mail Express 1030 AM Delivery

All in order:
$specialservicesdomestic: Certified MailRM USPS TrackingTM Insurance Priority Mail Express Insurance Adult Signature Restricted Delivery Adult Signature Required Registered without Insurance Registered MailTM Collect on Delivery Return Receipt for Merchandise Return Receipt Certificate of Mailing (Form 3817) Signature ConfirmationTM Priority Mail Express 1030 AM Delivery
*/

//@@TODO
// Return Receipt Electronic 110 (16) missing
        $serviceOptions = explode(', ', MODULE_SHIPPING_USPS_DMST_SERVICES); // domestic
        $specialservicesdomestic = '';
        foreach ($serviceOptions as $key => $val) {
            if (strlen($serviceOptions[$key]) > 1) {
                if ($serviceOptions[$key+1] == 'C' || $serviceOptions[$key+1] == 'S' || $serviceOptions[$key+1] == 'Y') {
                    switch ($serviceOptions[$key]) {
                        case 'Certified MailRM':
                            $specialservicesdomestic .= '  <SpecialService>105</SpecialService>' . "\n"; // 0
                            break;
                        case 'Insurance':
                            $specialservicesdomestic .= '  <SpecialService>100</SpecialService>' . "\n"; // 1
                            break;
                        case 'Registered MailTM':
                            $specialservicesdomestic .= '  <SpecialService>109</SpecialService>' . "\n"; // 5 docs said 4
                            break;
                        case 'Collect on Delivery':
                            $specialservicesdomestic .= '  <SpecialService>103</SpecialService>' . "\n"; // 6
                            break;
                        case 'Return Receipt for Merchandise':
                            $specialservicesdomestic .= '  <SpecialService>107</SpecialService>' . "\n"; // 7
                            break;
                        case 'Return Receipt':
                            $specialservicesdomestic .= '  <SpecialService>102</SpecialService>' . "\n"; // 8
                            break;
                        case 'Certificate of Mailing (Form 3665)':
                            $specialservicesdomestic .= '  <SpecialService>160</SpecialService>' . "\n"; // 10
                            break;
                        case 'Certificate of Mailing (Form 3817)':
                            $specialservicesdomestic .= '  <SpecialService>104</SpecialService>' . "\n"; // 9
                            break;
                        case 'Priority Mail Express Insurance':
                            $specialservicesdomestic .= '  <SpecialService>101</SpecialService>' . "\n"; // 11
                            break;
                        case 'Priority Mail Insurance':
                            $specialservicesdomestic .= '  <SpecialService>125</SpecialService>' . "\n"; // 1
                            break;
                        case 'USPS TrackingTM Electronic':
                            $specialservicesdomestic .= '  <SpecialService>155</SpecialService>' . "\n"; // 12 docs said 13
                            break;
                        case 'USPS TrackingTM':
                            $specialservicesdomestic .= '  <SpecialService>106</SpecialService>' . "\n"; // 13
                            break;
                        case 'Signature ConfirmationTM Electronic':
                            $specialservicesdomestic .= '  <SpecialService>156</SpecialService>' . "\n"; // 14 docs said 15
                            break;
                        case 'Signature ConfirmationTM':
                            $specialservicesdomestic .= '  <SpecialService>108</SpecialService>' . "\n"; // 15
                            break;
                        case 'Adult Signature Required':
                            $specialservicesdomestic .= '  <SpecialService>119</SpecialService>' . "\n"; // 19
                            break;
                        case 'Adult Signature Restricted Delivery':
                            $specialservicesdomestic .= '  <SpecialService>120</SpecialService>' . "\n"; // 20
                            break;
                        case 'Priority Mail Express 1030 AM Delivery':
                            $specialservicesdomestic .= '  <SpecialService>161</SpecialService>' . "\n"; // 200 (not currently working)
                            break;
                        // added 2015_0531
                        case 'Certified MailRM Restricted Delivery':
                            $specialservicesdomestic .= '  <SpecialService>170</SpecialService>' . "\n";
                            break;
                        case 'Certified MailRM Adult Signature Required':
                            $specialservicesdomestic .= '  <SpecialService>171</SpecialService>' . "\n";
                            break;
                        case 'Certified MailRM Adult Signature Restricted Delivery':
                            $specialservicesdomestic .= '  <SpecialService>172</SpecialService>' . "\n";
                            break;
                        case 'Signature ConfirmationTM Restricted Delivery':
                            $specialservicesdomestic .= '  <SpecialService>173</SpecialService>' . "\n";
                            break;
                        case 'Signature ConfirmationTM Electronic Restricted Delivery':
                            $specialservicesdomestic .= '  <SpecialService>174</SpecialService>' . "\n";
                            break;
                        case 'Collect on Delivery Restricted Delivery':
                            $specialservicesdomestic .= '  <SpecialService>175</SpecialService>' . "\n";
                            break;
                        case 'Registered MailTM Restricted Delivery':
                            $specialservicesdomestic .= '  <SpecialService>176</SpecialService>' . "\n";
                            break;
                        case 'Insurance Restricted Delivery':
                            $specialservicesdomestic .= '  <SpecialService>177</SpecialService>' . "\n";
                            break;
                        case 'Insurance Restricted Delivery (Priority Mail Express)':
                            $specialservicesdomestic .= '  <SpecialService>178</SpecialService>' . "\n";
                            break;
                        case 'Insurance Restricted Delivery (Priority Mail)':
                            $specialservicesdomestic .= '  <SpecialService>179</SpecialService>' . "\n";
                            break;
                        default:
                            break;
                    }
                }
            }
        }
        if ($specialservicesdomestic !== '') {
            $specialservicesdomestic = '<SpecialServices>' . $specialservicesdomestic . '</SpecialServices>';
        }
        return $specialservicesdomestic;
    }

    // return ExtraService tags based on checked choices only
    protected function extra_service() 
    {
/*
The extra service definitions are as follows:
USPS Extra Service Name ServiceID - Our Extra Service Name
 Registered Mail 0 - Registered Mail
 Insurance 1 - Insurance
 Return Receipt 2 - Return Receipt
 Certificate of Mailing 6 - Certificate of Mailing
 Electronic USPS Delivery Confirmation International 9 - Electronic USPS Delivery Confirmation International
*/
        $iserviceOptions = explode(', ', MODULE_SHIPPING_USPS_INTL_SERVICES);
        $extraserviceinternational = '';
        foreach ($iserviceOptions as $key => $val) {
            if (strlen($iserviceOptions[$key]) > 1) {
                if ($iserviceOptions[$key+1] == 'C' || $iserviceOptions[$key+1] == 'S' || $iserviceOptions[$key+1] == 'Y') {
                    switch ($iserviceOptions[$key]) {
                        case 'Registered Mail':
                            $extraserviceinternational .= '  <ExtraService>0</ExtraService>' . "\n";
                            break;
                        case 'Insurance':
                            $extraserviceinternational .= '  <ExtraService>1</ExtraService>' . "\n";
                            break;
                        case 'Return Receipt':
                            $extraserviceinternational .= '  <ExtraService>2</ExtraService>' . "\n";
                            break;
                        case 'Certificate of Mailing':
                            $extraserviceinternational .= '  <ExtraService>6</ExtraService>' . "\n";
                            break;
                        case 'Electronic USPS Delivery Confirmation International':
                            $extraserviceinternational .= '  <ExtraService>9</ExtraService>' . "\n";
                            break;
                        default:
                            break;
                    }
                }
            }
        }
        if ($extraserviceinternational !== '') {
            $extraserviceinternational = '<ExtraServices>' . $extraserviceinternational . '</ExtraServices>';
        }
        return $extraserviceinternational;
    }
}

// admin display functions inspired by osCbyJetta
function zen_cfg_usps_services($select_array, $key_value, $key = '')
{
    $key_values = explode( ", ", $key_value);
    $name = (($key) ? 'configuration[' . $key . '][]' : 'configuration_value');
    $string = '<b><div style="width:20px;float:left;text-align:center;">&nbsp;</div><div style="width:60px;float:left;text-align:center;">Min</div><div style="width:60px;float:left;text-align:center;">Max</div><div style="float:left;"></div><div style="width:60px;float:right;text-align:center;">Handling</div></b><div style="clear:both;"></div>';
    $string_spacing = '<div><br /><br /><b>&nbsp;International Rates:</b><br /></div>' . $string;
    $string_spacing_international = 0;
    $string = '<div><br /><b>&nbsp;Domestic Rates:</b><br /></div>' . $string;
    for ($i = 0; $i < count($select_array); $i++) {
//echo 'USPS $select_array[$i]: ' . $select_array[$i] . '<br />';
        if (preg_match("/international/i", $select_array[$i])) {
          $string_spacing_international ++;
        }
        if ($string_spacing_international == 1) {
          $string.= $string_spacing;
        }

        $string .= '<div id="' . $key . $i . '">';
        $string .= '<div style="width:20px;float:left;text-align:center;">' . zen_draw_checkbox_field($name, $select_array[$i], (in_array($select_array[$i], $key_values) ? 'CHECKED' : '')) . '</div>';
        if (in_array($select_array[$i], $key_values)) next($key_values);
        $string .= '<div style="width:60px;float:left;text-align:center;">' . zen_draw_input_field($name, current($key_values), 'size="5"') . '</div>';
        next($key_values);
        $string .= '<div style="width:60px;float:left;text-align:center;">' . zen_draw_input_field($name, current($key_values), 'size="5"') . '</div>';
        next($key_values);
        $string .= '<div style="float:left;">' . preg_replace(array('/RM/', '/TM/', '/International/', '/Envelope/', '/ Mail/', '/Large/', '/Medium/', '/Small/', '/First/', '/Legal/', '/Padded/', '/Flat Rate/', '/Regional Rate/', '/Express Guaranteed /', '/Package\hService\h-\hRetail/', '/Package Service/' ), array('', '', 'Intl', 'Env', '', 'Lg.', 'Md.', 'Sm.', '1st', 'Leg.', 'Pad.', 'F/R', 'R/R', 'Exp Guar', 'Pkgs - Retail', 'Pkgs - Comm'), $select_array[$i]) . '</div>';
        $string .= '<div style="width:60px;float:right;text-align:center;">$' . zen_draw_input_field($name, current($key_values), 'size="4"') . '</div>';
        next($key_values);
        $string .= '<div style="clear:both;"></div></div>';
    }
    return $string;
}

function zen_cfg_usps_extraservices($select_array, $key_value, $key = '')
{
    $key_values = explode( ", ", $key_value);
    $name = (($key) ? 'configuration[' . $key . '][]' : 'configuration_value');
    $string = '<b><div style="width:20px;float:left;text-align:center;">N</div><div style="width:20px;float:left;text-align:center;">Y</div></b><div style="clear:both;"></div>';
    for ($i=0; $i < sizeof($select_array); $i++) {
        $string .= zen_draw_hidden_field($name, $select_array[$i]);
        next($key_values);
        $string .= '<div id="' . $key . $i . '">';
        $string .= '<div style="width:20px;float:left;text-align:center;"><input type="checkbox" name="' . $name . '" value="N" ' . (current($key_values) == 'N' || current($key_values) == '' ? 'CHECKED' : '') . ' id="N-'.$key.$i.'" onClick="if(this.checked==1)document.getElementById(\'Y-'.$key.$i.'\').checked=false;else document.getElementById(\'Y-'.$key.$i.'\').checked=true;"></div>';
        $string .= '<div style="width:20px;float:left;text-align:center;"><input type="checkbox" name="' . $name . '" value="Y" ' . (current($key_values) == 'Y' ? 'CHECKED' : '') . ' id="Y-'.$key.$i.'" onClick="if(this.checked==1)document.getElementById(\'N-'.$key.$i.'\').checked=false;else document.getElementById(\'N-'.$key.$i.'\').checked=true;"></div>';
        next($key_values);
        $string .= preg_replace(array('/Signature/', '/without/', '/Merchandise/', '/TM/', '/RM/'), array('Sig', 'w/out', 'Merch.', '', ''), $select_array[$i]) . '<br />';
        $string .= '<div style="clear:both;"></div></div>';
    }
    return $string;
}

  /**
   * this is ONLY here to offer compatibility with ZC versions prior to v1.5.2
   */
if (!function_exists('plugin_version_check_for_updates')) {
    function plugin_version_check_for_updates($plugin_file_id = 0, $version_string_to_compare = '', $strict_zc_version_compare = false)
    {
        if ($plugin_file_id == 0) return false;
        $new_version_available = false;
        $lookup_index = $errno = 0;
        $response = $error = '';
        $url1 = 'https://plugins.zen-cart.com/versioncheck/'.(int)$plugin_file_id;
        $url2 = 'https://www.zen-cart.com/versioncheck/'.(int)$plugin_file_id;

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL,$url1);
            curl_setopt($ch, CURLOPT_VERBOSE, 0);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 9);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 9);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Plugin Version Check [' . (int)$plugin_file_id . '] ' . HTTP_SERVER);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $errno = curl_errno($ch);

            if ($errno > 0) {
                trigger_error('CURL error checking plugin versions: ' . $errno . ':' . $error . "\nTrying http instead.");
                curl_setopt($ch, CURLOPT_URL, str_replace('tps:', 'tp:', $url1));
                $response = curl_exec($ch);
                $error = curl_error($ch);
                $errno = curl_errno($ch);
            }
            if ($errno > 0) {
                trigger_error('CURL error checking plugin versions: ' . $errno . ':' . $error . "\nTrying www instead.");
                curl_setopt($ch, CURLOPT_URL, str_replace('tps:', 'tp:', $url2));
                $response = curl_exec($ch);
                $error = curl_error($ch);
                $errno = curl_errno($ch);
            }
            curl_close($ch);
        } else {
            $errono = 9999;
            $error = 'curl_init not found in PHP';
        }
        if ($errno > 0 || $response == '') {
            trigger_error('CURL error checking plugin versions: ' . $errno . ':' . $error . "\nTrying file_get_contents() instead.");
            $ctx = stream_context_create(array('http' => array('timeout' => 5)));
            $response = file_get_contents($url1, null, $ctx);
            if ($response === false) {
                trigger_error('file_get_contents() error checking plugin versions.' . "\nTrying http instead.");
                $response = file_get_contents(str_replace('tps:', 'tp:', $url1), null, $ctx);
            }
            if ($response === false) {
                trigger_error('file_get_contents() error checking plugin versions.' . "\nAborting.");
                return false;
            }
        }

        $data = json_decode($response, true);
        if (!$data || !is_array($data)) return false;
        // compare versions
        if (strcmp($data[$lookup_index]['latest_plugin_version'], $version_string_to_compare) > 0) $new_version_available = true;
        // check whether present ZC version is compatible with the latest available plugin version
        $zc_version = PROJECT_VERSION_MAJOR . '.' . preg_replace('/[^0-9.]/', '', PROJECT_VERSION_MINOR);
        if ($strict_zc_version_compare) $zc_version = PROJECT_VERSION_MAJOR . '.' . PROJECT_VERSION_MINOR;
        if (!in_array('v'. $zc_version, $data[$lookup_index]['zcversions'])) $new_version_available = false;
        return ($new_version_available) ? $data[$lookup_index] : false;
    }
}
