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
    const ORDER_STATE_AWAITING_PAYMENT = 'EFIVE_MANDAT_OS_AWAITING_PAYMENT';

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

        $this->displayName = $this->l('Administrative Mandat');
        $this->description = $this->l('Accept administrative mandat during the checkout.');
        $this->confirmUninstall = $this->l('Are you sure about removing the module ?');
        if ((!isset($this->mail) || !isset($this->details) || !isset($this->address)) && $this->active) {
            $this->warning = $this->l('The mail and account details must be configured before using this module.');
        }
        if (!count(Currency::checkPaymentCurrencies($this->id)) && $this->active) {
            $this->warning = $this->l('No currency has been set for this module.');
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
            || !$this->installOrderState()
        ) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        if (!Configuration::deleteByName('EFIVE_MANDAT_DETAILS')
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
                $this->_postErrors[] = $this->l(
                    'Account details are required.',
                    [],
                    'Modules.Efivemandat.Admin'
                );
            }
            if (!Tools::getValue('EFIVE_MANDAT_MAIL')) {
                $this->_postErrors[] = $this->l(
                    'Mail is required.',
                    [],
                    'Modules.Efivemandat.Admin'
                );
            }
            if (!Tools::getValue('EFIVE_MANDAT_ADDRESS')) {
                $this->_postErrors[] = $this->l(
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
                ->setCallToActionText($this->l('Pay using administrative mandat'))
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
                    'title' => $this->l('Details'),
                    'icon' => 'icon-envelope',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Mail to send the administrative mandat'),
                        'name' => 'EFIVE_MANDAT_MAIL',
                        'required' => true,
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('List of documents'),
                        'name' => 'EFIVE_MANDAT_DETAILS',
                        'desc' => $this->l('Change the text of list of documents to return you.'),
                        'required' => true,
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Address for the mandat'),
                        'name' => 'EFIVE_MANDAT_ADDRESS',
                        'desc' => $this->l('Address where the mandat should be written to.'),
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
                    'title' => $this->l('Customization'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Display the invitation to pay in the order confirmation page'),
                        'name' => self::FLAG_DISPLAY_PAYMENT_INVITE,
                        'is_bool' => true,
                        'hint' => $this->l('Your country\'s legislation may require you to send the invitation to pay by email only. Disabling the option will hide the invitation on the confirmation page.'),
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
        return [
            'EFIVE_MANDAT_DETAILS' => Tools::getValue('EFIVE_MANDAT_DETAILS', $this->details),
            'EFIVE_MANDAT_MAIL' => Tools::getValue('EFIVE_MANDAT_MAIL', $this->mail),
            'EFIVE_MANDAT_ADDRESS' => Tools::getValue('EFIVE_MANDAT_ADDRESS', $this->address),
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
            $this->l('%1$s (tax incl.)'),
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

        return [
            'total' => $total,
            'mandatDetails' => $mandatDetails,
            'mandatAddress' => $mandatAddress,
            'mandatEmail' => $mandatEmail
        ];
    }

    public function installOrderState()
    {
        if (Configuration::getGlobalValue(Efive_mandat::ORDER_STATE_AWAITING_PAYMENT)) {

            $orderState = new OrderState((int) Configuration::getGlobalValue(Efive_mandat::ORDER_STATE_AWAITING_PAYMENT));

            if (Validate::isLoadedObject($orderState) && $this->name === $orderState->module_name) {
                return true;
            }
        }

        return $this->createOrderState(
            static::ORDER_STATE_AWAITING_PAYMENT,
            [
                'en' => 'Awaiting payment by administrative mandat',
                'fr' => 'En attente du mandat administratif',
                'es' => 'En espera del mandato administrativo',
            ],
            '#000091',
        );
    }

    /**
     * Create custom OrderState used for payment
     *
     * @param string $configurationKey Configuration key used to store OrderState identifier
     * @param array $nameByLangIsoCode An array of name for all languages, default is en
     * @param string $color Color of the label
     * @param bool $isLogable consider the associated order as validated
     * @param bool $isPaid set the order as paid
     * @param bool $isInvoice allow a customer to download and view PDF versions of his/her invoices
     * @param bool $isShipped set the order as shipped
     * @param bool $isDelivery show delivery PDF
     * @param bool $isPdfDelivery attach delivery slip PDF to email
     * @param bool $isPdfInvoice attach invoice PDF to email
     * @param bool $isSendEmail send an email to the customer when his/her order status has changed
     * @param string $template Only letters, numbers and underscores are allowed. Email template for both .html and .txt
     * @param bool $isHidden hide this status in all customer orders
     * @param bool $isUnremovable Disallow delete action for this OrderState
     * @param bool $isDeleted Set OrderState deleted
     *
     * @return bool
     */
    private function createOrderState(
        $configurationKey,
        array $nameByLangIsoCode,
        $color,
        $isLogable = false,
        $isPaid = false,
        $isInvoice = false,
        $isShipped = false,
        $isDelivery = false,
        $isPdfDelivery = false,
        $isPdfInvoice = false,
        $isSendEmail = false,
        $template = '',
        $isHidden = false,
        $isUnremovable = true,
        $isDeleted = false
    ) {
        $tabNameByLangId = [];

        foreach ($nameByLangIsoCode as $langIsoCode => $name) {
            foreach (Language::getLanguages(false) as $language) {
                if (Tools::strtolower($language['iso_code']) === $langIsoCode) {
                    $tabNameByLangId[(int) $language['id_lang']] = $name;
                } elseif (isset($nameByLangIsoCode['en'])) {
                    $tabNameByLangId[(int) $language['id_lang']] = $nameByLangIsoCode['en'];
                }
            }
        }

        $orderState = new OrderState();
        $orderState->module_name = $this->name;
        $orderState->name = $tabNameByLangId;
        $orderState->color = $color;
        $orderState->logable = $isLogable;
        $orderState->paid = $isPaid;
        $orderState->invoice = $isInvoice;
        $orderState->shipped = $isShipped;
        $orderState->delivery = $isDelivery;
        $orderState->pdf_delivery = $isPdfDelivery;
        $orderState->pdf_invoice = $isPdfInvoice;
        $orderState->send_email = $isSendEmail;
        $orderState->hidden = $isHidden;
        $orderState->unremovable = $isUnremovable;
        $orderState->template = $template;
        $orderState->deleted = $isDeleted;
        $result = (bool) $orderState->add();

        if (false === $result) {
            $this->_errors[] = sprintf(
                'Failed to create OrderState %s',
                $configurationKey
            );

            return false;
        }

        $result = (bool) Configuration::updateGlobalValue($configurationKey, (int) $orderState->id);

        if (false === $result) {
            $this->_errors[] = sprintf(
                'Failed to save OrderState %s to Configuration',
                $configurationKey
            );

            return false;
        }

        $orderStateImgPath = $this->getLocalPath() . 'views/img/orderstate/' . $configurationKey . '.gif';

        if (false === (bool) Tools::file_exists_cache($orderStateImgPath)) {
            $this->_errors[] = sprintf(
                'Failed to find icon file of OrderState %s',
                $configurationKey
            );

            return false;
        }

        if (false === (bool) Tools::copy($orderStateImgPath, _PS_ORDER_STATE_IMG_DIR_ . $orderState->id . '.gif')) {
            $this->_errors[] = sprintf(
                'Failed to copy icon of OrderState %s',
                $configurationKey
            );

            return false;
        }
        return true;
    }
}
