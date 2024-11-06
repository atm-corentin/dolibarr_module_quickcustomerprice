<?php
if (!defined("NOCSRFCHECK")) define('NOCSRFCHECK', 1);
if (!defined("NOTOKENRENEWAL")) define('NOTOKENRENEWAL', 1);

require('../config.php');
dol_include_once('/comm/propal/class/propal.class.php');
dol_include_once('/compta/facture/class/facture.class.php');
dol_include_once('/commande/class/commande.class.php');
dol_include_once('/fourn/class/fournisseur.commande.class.php');
dol_include_once('/fourn/class/fournisseur.facture.class.php');
dol_include_once('/supplier_proposal/class/supplier_proposal.class.php');

$put = GETPOST('put');
$get = GETPOST('get');
$objectid = GETPOST('objectid');
$objectelement = GETPOST('objectelement');
$lineid = GETPOST('lineid');
$lineid = abs((int)filter_var($lineid, FILTER_SANITIZE_NUMBER_INT));
$lineclass = GETPOST('lineclass');
$type = GETPOST('type');
$value = GETPOST('value');
$code_extrafield = GETPOST('code_extrafield');

switch ($put) {
	case 'price':
		$Tab = _updateObjectLine(
			GETPOST('objectid'),
			GETPOST('objectelement'),
			GETPOST('lineid'),
			GETPOST('column'),
			GETPOST('value')
		);
		echo json_encode($Tab);
		break;
	case 'extrafield-value':
		echo _saveExtrafield($lineid, $lineclass, $type, $code_extrafield, $value);
		break;
}

switch ($get) {
	case 'extrafield-value':
		echo _showExtrafield($objectelement, $lineid, $code_extrafield);
		break;
}

function _updateObjectLine($objectid, $objectelement, $lineid, $column, $value) {
	global $db, $conf, $langs, $user, $hookmanager;
	$error = 0;

	$Tab = array();
	if ($objectelement == "order_supplier") $objectelement = "CommandeFournisseur";
	if ($objectelement == "invoice_supplier") $objectelement = "FactureFournisseur";
	if ($objectelement == "supplier_proposal") $objectelement = "SupplierProposal";

	$o = new $objectelement($db);
	$o->fetch($objectid);

	if (getDolGlobalString('QCP_ALLOW_CHANGE_ON_VALIDATE')) {
		$o->statut = $objectelement::STATUS_DRAFT;
	}

	$find = false;
	foreach ($o->lines as &$line) {
		if ($line->id == $lineid || $line->rowid == $lineid) {
			$find = true;
			break;
		}
	}

	if ($find) {
		$qty = $line->qty;
		$price = $line->subprice;
		$remise_percent = $line->remise_percent;
		if (!empty($line->pu_ht_devise)) $pu_ht_devise = $line->pu_ht_devise;
		$pa_ht = $line->pa_ht;
		if (empty($remise_percent)) $remise_percent = 0;
		$situation_cycle_ref = empty($line->situation_percent) ? 0 : $line->situation_percent;

		// Gestion de la modification des taux de marge et de marque
		if ($column == 'marge_tx') {
			$line->marge_tx = floatval($value);
			$price = calculatePriceFromMargin($pa_ht, $line->marge_tx); // Recalcul du prix de vente
		} elseif ($column == 'marque_tx') {
			$line->marque_tx = floatval($value);
			$pa_ht = calculateCostFromMark($price, $line->marque_tx); // Recalcul du prix de revient
		} else {
			${$column} = price2num($value);
		}

		if (empty($line->fk_fournprice)) $line->fk_fournprice = 0;
		$marginInfos = getMarginInfos($price, $remise_percent, $line->tva_tx, $line->localtax1_tx, $line->localtax2_tx, $line->fk_fournprice, $pa_ht);
		$line->marge_tx = $marginInfos[1];
		$line->marque_tx = $marginInfos[2];

		// Mises à jour spécifiques pour chaque type d'objet
		if ($objectelement == 'facture') {
			$res = $o->updateline($lineid, $line->desc, $price, $qty, $remise_percent, $line->date_start, $line->date_end, $line->tva_tx, $line->localtax1_tx, $line->localtax2_tx, 'HT', $line->info_bits, $line->product_type, $line->fk_parent_line, 0, $line->fk_fournprice, $pa_ht, $line->label, $line->special_code, $line->array_options, $situation_cycle_ref, $line->fk_unit, $pu_ht_devise);
		} elseif ($objectelement == 'commande') {
			$res = $o->updateline($lineid, $line->desc, $price, $qty, $remise_percent, $line->tva_tx, $line->localtax1_tx, $line->localtax2_tx, 'HT', $line->info_bits, $line->date_start, $line->date_end, $line->product_type, $line->fk_parent_line, 0, $line->fk_fournprice, $pa_ht, $line->label, $line->special_code, $line->array_options, $line->fk_unit, $pu_ht_devise);
		} elseif ($objectelement == 'propal') {
			$res = $o->updateline($lineid, $price, $qty, $remise_percent, $line->tva_tx, $line->localtax1_tx, $line->localtax2_tx, $line->desc, 'HT', $line->info_bits, $line->special_code, $line->fk_parent_line, 0, $line->fk_fournprice, $pa_ht, $line->label, $line->product_type, $line->date_start, $line->date_end, $line->array_options, $line->fk_unit, $pu_ht_devise);
		}

		if ($res >= 0) {
			$Tab = array(
				'total_ht' => price($o->total_ht),
				'multicurrency_total_ht' => price($o->multicurrency_total_ht),
				'qty' => $qty,
				'pa_ht' => price($pa_ht),
				'marge_tx' => price($line->marge_tx, 2) . ' %',
				'marque_tx' => price($line->marque_tx, 2) . ' %',
				'price' => price($price),
				'situation_cycle_ref' => $situation_cycle_ref,
				'remise_percent' => $remise_percent,
				'uttc' => price($line->subprice + ($line->subprice * $line->tva_tx) / 100),
				'pu_ht_devise' => price($pu_ht_devise)
			);
		} else {
			$Tab = array(
				'error' => 'updateFailed',
				'msg' => $o->error
			);
		}
	} else {
		$Tab = array('error' => 'noline');
	}

	return $Tab;
}

/**
 * Recalcule le prix de vente à partir du coût et du taux de marge
 * @param float $cost
 * @param float $marginRate
 * @return float
 */
function calculatePriceFromMargin($cost, $marginRate) {
	return $cost * (1 + $marginRate / 100);
}

/**
 * Recalcule le prix de revient à partir du prix de vente et du taux de marque
 * @param float $price
 * @param float $markRate
 * @return float
 */
function calculateCostFromMark($price, $markRate) {
	return $price / (1 + $markRate / 100);
}
/**
 * @param CommonObject     $o
 * @param CommonObjectLine $line
 * @param float|null       $price
 * @param float|null       $pu_ht_devise
 * @return void
 */
function handleMulticurrencyPrices(CommonObject $o, CommonObjectLine $line, ?float &$price, ?float &$pu_ht_devise): void {
	if(is_null($pu_ht_devise)) $pu_ht_devise = 0;
	if(!isset($line->pu_ht_devise)) $line->pu_ht_devise = 0;
	if(is_null($price)) $price = 0;
	if(price($price) != price($line->subprice)) $pu_ht_devise = $price * $o->multicurrency_tx;
	else if(price($pu_ht_devise) != price($line->pu_ht_devise)) $price = $pu_ht_devise / $o->multicurrency_tx;
	else $pu_ht_devise = $line->multicurrency_subprice;
}

/**
 * @param CommonObjectLine $line
 * @param float $price
 * @return int return > 0 if error
 */
function checkPriceMin(CommonObjectLine $line, float $price) : int {
	global $langs, $db, $user, $conf;

	$error = 0;

	$product = new Product($db);
	$product->fetch($line->fk_product);

	$price_min = $product->price_min;
	if(getDolGlobalString('PRODUIT_MULTIPRICES') && ! empty($o->thirdparty->price_level)) $price_min = $product->multiprices_min [$o->thirdparty->price_level];

	if(((getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && ! $user->hasRight('produit', 'ignore_price_min_advance')) || ! getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) && ($price_min && (price2num($price) * (1 - price2num(floatval(GETPOST('remise_percent'))) / 100) < price2num($price_min)))) {
		$langs->load('products');
		$o->error = $langs->trans('CantBeLessThanMinPrice', price(price2num($price_min, 'MU'), 0, $langs, 0, 0, -1, $conf->currency));
		$error++;
	}

	return $error;
}

function _showExtrafield($objectelement, $lineid, $code_extrafield) {
	global $db;
	if ($objectelement == "order_supplier") $lineclass = "CommandeFournisseurLigne";
	if ($objectelement == "invoice_supplier") $lineclass = "SupplierInvoiceLine";
	if ($objectelement == "supplier_proposal") $lineclass = "SupplierProposalLine";
	if ($objectelement == "facture") $lineclass = "FactureLigne";
	if ($objectelement == "commande") $lineclass = "OrderLine";
	if ($objectelement == "propal") $lineclass = "PropaleLigne";

	$extrafields = new ExtraFields($db);
	$line = new $lineclass($db);
	$line->fetch($lineid);
	$line->fetch_optionals();
	$extrafields->fetch_name_optionals_label($line->element);
	if (floatval(DOL_VERSION) >= 17) $showInputField = $extrafields->showInputField($code_extrafield, $line->array_options['options_'.$code_extrafield] ?? null, '', '', '', '', 0, $line->element);
	else $showInputField = $extrafields->showInputField($code_extrafield, $line->array_options['options_'.$code_extrafield]);

	if (floatval(DOL_VERSION) >= 17) $type = $extrafields->attributes[$line->element]['type'][$code_extrafield];
	else $type = $extrafields->attribute_type[$code_extrafield];

	return $showInputField
		.'&nbsp;&nbsp;<span class="quickSaveExtra" style="cursor:pointer;" type="'.$type.'" extracode="'.$code_extrafield.'" lineid="'.$lineid.'" lineclass="'.$lineclass.'"><i class="fa fa-check" aria-hidden="true"></i></span>';

}

function _saveExtrafield($lineid, $lineclass, $type, $code_extrafield, $value) {
	global $db, $user;
	$line = new $lineclass($db);
	$line->fetch($lineid);
	$line->fetch_optionals();
	$extrafields = new ExtraFields($db);
	$extrafields->fetch_name_optionals_label($line->element);
	if (floatval(DOL_VERSION) >= 17) $type = $extrafields->attributes[$line->element]['type'][$code_extrafield];
	else $type = $extrafields->attribute_type[$code_extrafield];
	if(($type == 'datetime' || $type == 'date') && !empty($value)) $value = (int) $value;

	if(is_array($value)) $value = implode(',', $value);
	$line->array_options['options_' . $code_extrafield] = $value;
	if($lineclass !== "OrderLine") $line->update();
	else $line->update($user);
	if (floatval(DOL_VERSION) >= 17) return $extrafields->showOutputField($code_extrafield, $line->array_options['options_'.$code_extrafield], '', $line->element);
	else return $extrafields->showOutputField($code_extrafield, $line->array_options['options_' . $code_extrafield]);

}
