<?php
/**
 * 2007-2020 PrestaShop and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2020 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Efive_Mandat extends PaymentModule
{
    const FLAG_DISPLAY_PAYMENT_INVITE = 'EFIVE_MANDAT_PAYMENT_INVITE';

    protected $_html = '';
    protected $_postErrors = [];

    public $details;
    public $mail;
    public $address;
    public $extra_mail_vars;
    /**
     * @var int
     */
    public $is_eu_compatible;

    public function __construct()
    {
        $this->name = 'efive_mandat';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];
        $this->author = 'Valentin HUARD';
        $this->controllers = ['payment', 'validation'];
        $this->is_eu_compatible = 1;

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $config = Configuration::getMultiple(['EFIVE_MANDAT_DETAILS', 'EFIVE_MANDAT_MAIL', 'EFIVE_MANDAT_ADDRESS']);
        if (!empty($config['EFIVE_MANDAT_MAIL'])) {
            $this->mail = $config['EFIVE_MANDAT_MAIL'];
        }
        if (!empty($config['EFIVE_MANDAT_DETAILS'])) {
            $this->details = $config['EFIVE_MANDAT_DETAILS'];
        }
        if (!empty($config['EFIVE_MANDAT_ADDRESS'])) {
            $this->address = $config['EFIVE_MANDAT_ADDRESS'];
        }

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('Administrative Mandat', [], 'Modules.Efivemandat.Admin');
        $this->description = $this->trans('Accept administrative mandat during the checkout.', [], 'Modules.Efivemandat.Admin');
        $this->confirmUninstall = $this->trans('Are you sure about removing the module ?', [], 'Modules.Efivemandat.Admin');
        if ((!isset($this->mail) || !isset($this->details) || !isset($this->address)) && $this->active) {
            $this->warning = $this->trans('The mail and account details must be configured before using this module.', [], 'Modules.Efivemandat.Admin');
        }
        if (!count(Currency::checkPaymentCurrencies($this->id)) && $this->active) {
            $this->warning = $this->trans('No currency has been set for this module.', [], 'Modules.Efivemandat.Admin');
        }

        $this->extra_mail_vars = [
            '{mandat_mail}' => $this->mail,
            '{mandat_details}' => nl2br($this->details ?: ''),
            '{mandat_address}' => nl2br($this->address ?: ''),
        ];
    }

    public function install()
    {
        Configuration::updateValue(self::FLAG_DISPLAY_PAYMENT_INVITE, true);
        if (!parent::install()
            || !$this->registerHook('displayPaymentReturn')
            || !$this->registerHook('paymentOptions')
        ) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        if (!Configuration::deleteByName('EFIVE_MANDAT_CUSTOM_TEXT')
                || !Configuration::deleteByName('EFIVE_MANDAT_DETAILS')
                || !Configuration::deleteByName('EFIVE_MANDAT_MAIL')
                || !Configuration::deleteByName('EFIVE_MANDAT_ADDRESS')
                || !Configuration::deleteByName(self::FLAG_DISPLAY_PAYMENT_INVITE)
                || !parent::uninstall()) {
            return false;
        }

        return true;
    }

    protected function _postValidation()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue(
                self::FLAG_DISPLAY_PAYMENT_INVITE,
                Tools::getValue(self::FLAG_DISPLAY_PAYMENT_INVITE)
            );

            if (!Tools::getValue('EFIVE_MANDAT_DETAILS')) {
                $this->_postErrors[] = $this->trans(
                    'Account details are required.',
                    [],
                    'Modules.Efivemandat.Admin'
                );
            }
            if (!Tools::getValue('EFIVE_MANDAT_MAIL')) {
                $this->_postErrors[] = $this->trans(
                    'Mail is required.',
                    [],
                    'Modules.Efivemandat.Admin'
                );
            }
            if (!Tools::getValue('EFIVE_MANDAT_ADDRESS')) {
                $this->_postErrors[] = $this->trans(
                    'Address is required.',
                    [],
                    'Modules.Efivemandat.Admin'
                );
            }
        }
    }

    protected function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue('EFIVE_MANDAT_DETAILS', Tools::getValue('EFIVE_MANDAT_DETAILS'));
            Configuration::updateValue('EFIVE_MANDAT_MAIL', Tools::getValue('EFIVE_MANDAT_MAIL'));
            Configuration::updateValue('EFIVE_MANDAT_ADDRESS', Tools::getValue('EFIVE_MANDAT_ADDRESS'));

            $custom_text = [];
            $languages = Language::getLanguages(false);
            foreach ($languages as $lang) {
                if (Tools::getIsset('EFIVE_MANDAT_CUSTOM_TEXT_' . $lang['id_lang'])) {
                    $custom_text[$lang['id_lang']] = Tools::getValue('EFIVE_MANDAT_CUSTOM_TEXT_' . $lang['id_lang']);
                }
            }
            Configuration::updateValue('EFIVE_MANDAT_CUSTOM_TEXT', $custom_text);
        }
        $this->_html .= $this->displayConfirmation($this->trans('Settings updated', [], 'Admin.Global'));
    }

    protected function _displayBankWire()
    {
        return $this->display(__FILE__, 'infos.tpl');
    }

    public function getContent()
    {
        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->_postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        } else {
            $this->_html .= '<br />';
        }

        $this->_html .= $this->_displayBankWire();
        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return [];
        }

        if (!$this->checkCurrency($params['cart'])) {
            return [];
        }

        $this->smarty->assign(
            $this->getTemplateVarInfos()
        );

        $newOption = new PaymentOption();
        $newOption->setModuleName($this->name)
                ->setCallToActionText($this->trans('Pay using administrative mandat', [], 'Modules.Efivemandat.Shop'))
                ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
                ->setAdditionalInformation($this->fetch('module:efive_mandat/views/templates/hook/efive_mandat_intro.tpl'));

        return [
            $newOption,
        ];
    }

    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active || !Configuration::get(self::FLAG_DISPLAY_PAYMENT_INVITE)) {
            return;
        }

        $mandatEmail = $this->mail;
        if (!$mandatEmail) {
            $mandatEmail = '___________';
        }

        $mandatDetails = Tools::nl2br($this->details);
        if (!$mandatDetails) {
            $mandatDetails = '___________';
        }

        $mandatAddress = Tools::nl2br($this->address);
        if (!$mandatAddress) {
            $mandatAddress = '___________';
        }

        $totalToPaid = $params['order']->getOrdersTotalPaid() - $params['order']->getTotalPaid();
        $this->smarty->assign([
            'shop_name' => $this->context->shop->name,
            'total' => $this->context->getCurrentLocale()->formatPrice(
                $totalToPaid,
                (new Currency($params['order']->id_currency))->iso_code
            ),
            'mandatDetails' => $mandatDetails,
            'mandatAddress' => $mandatAddress,
            'mandatEmail' => $mandatEmail,
            'status' => 'ok',
            'reference' => $params['order']->reference,
            'contact_url' => $this->context->link->getPageLink('contact', true),
        ]);

        return $this->fetch('module:efive_mandat/views/templates/hook/payment_return.tpl');
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }

        return false;
    }

    public function renderForm()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Details', [], 'Modules.Efivemandat.Admin'),
                    'icon' => 'icon-envelope',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Mail to send the administrative mandat', [], 'Modules.Efivemandat.Admin'),
                        'name' => 'EFIVE_MANDAT_MAIL',
                        'required' => true,
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->trans('List of documents', [], 'Modules.Efivemandat.Admin'),
                        'name' => 'EFIVE_MANDAT_DETAILS',
                        'desc' => $this->trans('Change the text of list of documents to return you.', [], 'Modules.Efivemandat.Admin'),
                        'required' => true,
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->trans('Address for the mandat', [], 'Modules.Efivemandat.Admin'),
                        'name' => 'EFIVE_MANDAT_ADDRESS',
                        'desc' => $this->trans('Address where the mandat should be written to.', [], 'Modules.Efivemandat.Admin'),
                        'required' => true,
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                ],
            ],
        ];
        $fields_form_customization = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Customization', [], 'Modules.Efivemandat.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'textarea',
                        'label' => $this->trans('Information to the customer', [], 'Modules.Efivemandat.Admin'),
                        'name' => 'EFIVE_MANDAT_CUSTOM_TEXT',
                        'desc' => $this->trans('Information on the processing (processing time, starting of the shipping...)', [], 'Modules.Efivemandat.Admin'),
                        'lang' => true,
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Display the invitation to pay in the order confirmation page', [], 'Modules.Efivemandat.Admin'),
                        'name' => self::FLAG_DISPLAY_PAYMENT_INVITE,
                        'is_bool' => true,
                        'hint' => $this->trans('Your country\'s legislation may require you to send the invitation to pay by email only. Disabling the option will hide the invitation on the confirmation page.', [], 'Modules.Efivemandat.Admin'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->trans('Yes', [], 'Admin.Global'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->trans('No', [], 'Admin.Global'),
                            ],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ?: 0;
        $helper->id = (int) Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure='
            . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$fields_form, $fields_form_customization]);
    }

    public function getConfigFieldsValues()
    {
        $custom_text = [];
        $languages = Language::getLanguages(false);
        foreach ($languages as $lang) {
            $custom_text[$lang['id_lang']] = Tools::getValue(
                'EFIVE_MANDAT_CUSTOM_TEXT_' . $lang['id_lang'],
                Configuration::get('EFIVE_MANDAT_CUSTOM_TEXT', $lang['id_lang'])
            );
        }

        return [
            'EFIVE_MANDAT_DETAILS' => Tools::getValue('EFIVE_MANDAT_DETAILS', $this->details),
            'EFIVE_MANDAT_MAIL' => Tools::getValue('EFIVE_MANDAT_MAIL', $this->mail),
            'EFIVE_MANDAT_ADDRESS' => Tools::getValue('EFIVE_MANDAT_ADDRESS', $this->address),
            'EFIVE_MANDAT_CUSTOM_TEXT' => $custom_text,
            self::FLAG_DISPLAY_PAYMENT_INVITE => Tools::getValue(
                self::FLAG_DISPLAY_PAYMENT_INVITE,
                Configuration::get(self::FLAG_DISPLAY_PAYMENT_INVITE)
            ),
        ];
    }

    public function getTemplateVarInfos()
    {
        $cart = $this->context->cart;
        $total = sprintf(
            $this->trans('%1$s (tax incl.)', [], 'Modules.Efivemandat.Shop'),
            $this->context->getCurrentLocale()->formatPrice($cart->getOrderTotal(true, Cart::BOTH), $this->context->currency->iso_code)
        );

        $mandatEmail = $this->mail;
        if (!$mandatEmail) {
            $mandatEmail = '___________';
        }

        $mandatDetails = Tools::nl2br($this->details);
        if (!$mandatDetails) {
            $mandatDetails = '___________';
        }

        $mandatAddress = Tools::nl2br($this->address);
        if (!$mandatAddress) {
            $mandatAddress = '___________';
        }

        $mandatCustomText = Tools::nl2br(Configuration::get('EFIVE_MANDAT_CUSTOM_TEXT', $this->context->language->id));
        if (empty($mandatCustomText)) {
            $mandatCustomText = '';
        }

        return [
            'total' => $total,
            'mandatDetails' => $mandatDetails,
            'mandatAddress' => $mandatAddress,
            'mandatEmail' => $mandatEmail,
            'mandatCustomText' => $mandatCustomText,
        ];
    }
}
