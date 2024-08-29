<?php
/**
 * ViraXpress - https://www.viraxpress.com
 *
 * LICENSE AGREEMENT
 *
 * This file is part of the ViraXpress package and is licensed under the ViraXpress license agreement.
 * You can view the full license at:
 * https://www.viraxpress.com/license
 *
 * By utilizing this file, you agree to comply with the terms outlined in the ViraXpress license.
 *
 * DISCLAIMER
 *
 * Modifications to this file are discouraged to ensure seamless upgrades and compatibility with future releases.
 *
 * @category    ViraXpress
 * @package     ViraXpress_ConfigurableProduct
 * @author      ViraXpress
 * @copyright   © 2024 ViraXpress (https://www.viraxpress.com/)
 * @license     https://www.viraxpress.com/license
 */

namespace ViraXpress\ConfigurableProduct\Plugin\ConfigurableProduct;

use Magento\ConfigurableProduct\Block\Product\View\Type\Configurable as TypeConfigurable;
use Magento\ConfigurableProduct\Model\ConfigurableAttributeData;
use Magento\Framework\Locale\Format;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Image;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Json\EncoderInterface;
use Magento\Catalog\Block\Product\Context;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable\Variations\Prices;
use Magento\ConfigurableProduct\Helper\Data as ConfigurableHelper;
use Magento\Framework\App\ObjectManager;

class JsonConfig
{

    /**
     * @var ConfigurableHelper
     */
    protected $helper;

    /**
     * @var ConfigurableAttributeData
     */
    protected $configurableAttributeData;

    /**
     * @var EncoderInterface
     */
    protected $jsonEncoder;

    /**
     * @var Format
     */
    private $localeFormat;

    /**
     * @var PriceCurrencyInterface
     */
    protected $priceCurrency;

    /**
     * @var Image
     */
    protected $imageHelper;

    /**
     * @var Prices
     */
    protected $variationPrices;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var \Magento\Tax\Helper\Data
     */
    protected $_taxData;

    /**
     * @param Context $context
     * @param ConfigurableHelper $helper
     * @param ConfigurableAttributeData $configurableAttributeData
     * @param EncoderInterface $jsonEncoder
     * @param PriceCurrencyInterface $priceCurrency
     * @param Image $imageHelper
     * @param ScopeConfigInterface $scopeConfig
     * @param Format|null $localeFormat
     * @param Prices|null $variationPrices
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        ConfigurableHelper $helper,
        ConfigurableAttributeData $configurableAttributeData,
        EncoderInterface $jsonEncoder,
        PriceCurrencyInterface $priceCurrency,
        Image $imageHelper,
        ScopeConfigInterface $scopeConfig,
        Format $localeFormat = null,
        Prices $variationPrices = null
    ) {
        $this->helper = $helper;
        $this->jsonEncoder = $jsonEncoder;
        $this->priceCurrency = $priceCurrency;
        $this->imageHelper = $imageHelper;
        $this->scopeConfig = $scopeConfig;
        $this->_taxData = $context->getTaxData();
        $this->configurableAttributeData = $configurableAttributeData;
        $this->localeFormat = $localeFormat ?: ObjectManager::getInstance()->get(Format::class);
        $this->variationPrices = $variationPrices ?: ObjectManager::getInstance()->get(
            \Magento\ConfigurableProduct\Model\Product\Type\Configurable\Variations\Prices::class
        );
    }

    /**
     * Returns additional values for js config, con be overridden by descendants
     *
     * @return array
     */
    protected function _getAdditionalConfig()
    {
        return [];
    }

    /**
     * After get json config.
     *
     * @param TypeConfigurable $subject
     * @param string $result
     * @return string
     */
    public function afterGetJsonConfig(
        TypeConfigurable $subject,
        $result
    ) {
        $store = $subject->getCurrentStore();
        $currentProduct = $subject->getProduct();

        $options = $this->helper->getOptions($currentProduct, $subject->getAllowProducts());
        $attributesData = $this->configurableAttributeData->getAttributesData($currentProduct, $options);

        $config = [
            'attributes' => $attributesData['attributes'],
            'template' => str_replace('%s', '<%- data.price %>', $store->getCurrentCurrency()->getOutputFormat()),
            'currencyFormat' => $store->getCurrentCurrency()->getOutputFormat(),
            'optionPrices' => $this->getOptionPrices($subject),
            'priceFormat' => $this->localeFormat->getPriceFormat(),
            'prices' => $this->variationPrices->getFormattedPrices($subject->getProduct()->getPriceInfo()),
            'productId' => $currentProduct->getId(),
            'chooseText' => __('Choose an Option...'),
            'images' => $this->getOptionImages($subject),
            'index' => isset($options['index']) ? $options['index'] : [],
            'salable' => $options['salable'] ?? [],
            'canDisplayShowOutOfStockStatus' => $options['canDisplayShowOutOfStockStatus'] ?? false
        ];

        if ($currentProduct->hasPreconfiguredValues() && !empty($attributesData['defaultValues'])) {
            $config['defaultValues'] = $attributesData['defaultValues'];
        }
        $config = array_merge($config, $this->_getAdditionalConfig());
        $result = $this->jsonEncoder->encode($config);
        return $result;
    }

    /**
     * Get product images for configurable variations
     *
     * @param object $subject The subject object
     * @return array
     * @since 100.1.10
     */
    public function getOptionImages($subject)
    {
        $images = [];
        foreach ($subject->getAllowProducts() as $product) {
            $productImages = $this->helper->getGalleryImages($product) ?: [];
            foreach ($productImages as $image) {
                $isViraXpressEnable = $this->scopeConfig->getValue('viraxpress_config/general/enable_viraxpress', ScopeInterface::SCOPE_STORE);
                $productImageUrl = $image->getData('large_image_url');
                if ($isViraXpressEnable) {
                    $imageConfig = $this->scopeConfig->getValue('viraxpress_config/image_resize/product_view_image_resize');
                    $imageWidth = ($imageConfig['width']) ? $imageConfig['width'] : 750;
                    $imageHeight = ($imageConfig['height']) ? $imageConfig['height'] : 930;
                    $productImageConfig = $this->imageHelper->init($product, 'product_page_image_large')->setImageFile($image->getFile())->resize($imageWidth, $imageHeight);
                    $productImageUrl = $productImageConfig->getUrl();
                }
                $images[$product->getId()][] =
                    [
                        'original_resize' => $image->getData('url'),
                        'thumb' => $image->getData('small_image_url'),
                        'img' => $image->getData('medium_image_url'),
                        'full' => $productImageUrl,
                        'caption' => $image->getLabel(),
                        'position' => $image->getPosition(),
                        'isMain' => $image->getFile() == $product->getImage(),
                        'type' =>  $image->getMediaType() ? str_replace('external-', '', $image->getMediaType()) : '',
                        'videoUrl' => $image->getVideoUrl(),
                    ];
            }
        }

        return $images;
    }

    /**
     * Collect price options
     *
     * @param mixed $subject
     * @return array
     */
    public function getOptionPrices($subject)
    {
        $prices = [];
        foreach ($subject->getAllowProducts() as $product) {
            $priceInfo = $product->getPriceInfo();

            $prices[$product->getId()] = [
                'baseOldPrice' => [
                    'amount' => $this->localeFormat->getNumber(
                        $priceInfo->getPrice('regular_price')->getAmount()->getBaseAmount()
                    ),
                ],
                'oldPrice' => [
                    'amount' => $this->localeFormat->getNumber(
                        $priceInfo->getPrice('regular_price')->getAmount()->getValue()
                    ),
                ],
                'basePrice' => [
                    'amount' => $this->localeFormat->getNumber(
                        $priceInfo->getPrice('final_price')->getAmount()->getBaseAmount()
                    ),
                ],
                'finalPrice' => [
                    'amount' => $this->localeFormat->getNumber(
                        $priceInfo->getPrice('final_price')->getAmount()->getValue()
                    ),
                ],
                'tierPrices' => $this->getTierPricesByProduct($product),
                'msrpPrice' => [
                    'amount' => $this->localeFormat->getNumber(
                        $this->priceCurrency->convertAndRound($product->getMsrp())
                    ),
                ],
            ];
        }

        return $prices;
    }

    /**
     * Returns product's tier prices list
     *
     * @param ProductInterface $product
     * @return array
     */
    public function getTierPricesByProduct(ProductInterface $product): array
    {
        $tierPrices = [];
        $tierPriceModel = $product->getPriceInfo()->getPrice('tier_price');
        foreach ($tierPriceModel->getTierPriceList() as $tierPrice) {
            $price = $this->_taxData->displayPriceExcludingTax() ?
                $tierPrice['price']->getBaseAmount() : $tierPrice['price']->getValue();

            $tierPriceData = [
                'qty' => $this->localeFormat->getNumber($tierPrice['price_qty']),
                'price' => $this->localeFormat->getNumber($price),
                'percentage' => $this->localeFormat->getNumber(
                    $tierPriceModel->getSavePercent($tierPrice['price'])
                ),
            ];

            if ($this->_taxData->displayBothPrices()) {
                $tierPriceData['basePrice'] = $this->localeFormat->getNumber(
                    $tierPrice['price']->getBaseAmount()
                );
            }

            $tierPrices[] = $tierPriceData;
        }

        return $tierPrices;
    }
}
