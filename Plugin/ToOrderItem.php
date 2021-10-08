<?php
/**
 * ToOrderItem.php
 *
 * Plugin to act before the conversion of Quote to Orders.
 *
 * This will capture orders made frontend, backend, and via M2E Pro.
 *
 * Desired outcome is not to show customers the extra items added, but add
 * them to the order at the last minute, with no way to modify them.
 */
namespace Makey\ExtraItems\Plugin;

use \Magento\Quote\Model\QuoteManagement;
use \Magento\Quote\Model\Quote;
use \Magento\Catalog\Model\ProductRepository;
use \Magento\Quote\Model\Quote\Item;
use \Psr\Log\LoggerInterface;
use \Magento\Catalog\Api\Data\ProductInterface;
use \Magento\Framework\Exception\NoSuchEntityException;

class ToOrderItem
{
    /**
     * @var ProductRepository
     */
    protected $_productRepository;
    /**
     * @var \Magento\Framework\Logger\Monolog
     */
    protected $_logger;

    /**
     * @param   \Magento\Catalog\Model\ProductRepository
     * @param   Psr\Log\LoggerInterface
     */
    public function __construct(ProductRepository $productRepository, LoggerInterface $logger)
    {
        $this->_productRepository = $productRepository;
        $this->_logger = $logger;
    }

    /**
     * beforeConvert
     *
     * @param QuoteManagement $subject
     * @param \Magento\Quote\Model\Quote
     * @param array $data
     *
     * @return \Magento\Sales\Model\Order
     */
    public function beforeSubmit(
        QuoteManagement $subject,
        $quote,
        $data = []
    ) {
        $this->_logger->info('[START - Plugin] ItemToOrder:beforeSubmit');
        /* @var $quote \Magento\Quote\Model\Quote */
        $this->_addExtraItemsToQuote($quote);
        $this->_logger->info('[DONE] ItemToOrder:beforeSubmit');

        return [$quote, $data];
    }

    /**
     * Add all extra items to quote
     *
     * @param   \Magento\Quote\Model\Quote\
     */
    protected function _addExtraItemsToQuote(Quote $quote)
    {
        foreach ($quote->getAllVisibleItems() as $quoteItem) {
            /* @var $$quoteItem \Magento\Quote\Model\Quote\Item */
            //$product = $quoteItem->getProduct();
            $product = $this->_productRepository->get($quoteItem->getSku());
            /**
             * Simples Only.
             */
//            if($product->getTypeId() != \Magento\Catalog\Model\Product\Type::TYPE_SIMPLE) {
//                $this->_logger->info("Skip product, not simple");
//                continue;
//            }

            /**
             * Skip products without "extras"
             */
            if( ! $this->_hasExtraProducts($product)) {
                $this->_logger->info(sprintf(
                    "[%s] NO extra products",
                    $product->getSku()
                ));
                continue;
            } else {

                /** replace main product  */

                if (!$this->_getReplaceMainProduct($product)) {
                    $this->_logger->info(sprintf("[%s] No need to replace main product!", $product->getSku()));
                } else {
                    $mainProduct = $this->_getMainProductSku($product);

                    $this->_logger->info(sprintf("[%s] deleting from yhe quote!", $product->getSku()));
                    $this->_replaceMainProduct($quote, $quoteItem, $mainProduct);

                }

                $extraProducts = $this->_getExtraProducts($product);

                $this->_logger->info(sprintf('Extra products for [' . $quoteItem->getSku() . ']', $extraProducts));

                /** Add each Extra Item to the quote, or update it's Qty. */
                foreach ($extraProducts as $extraProductSku) {
                    $this->_addExtraItemToQuote($quote, $quoteItem, $extraProductSku);
                }

            }

        }
    }

    /**
     * @param   \Magento\Quote\Model\Quote\
     * @param   \Magento\Quote\Model\Quote\Item
     * @param   string
     * @return  void
     */

    protected function _replaceMainProduct(Quote $quote, Item $parentItem, $sku)
    {
        $this->_logger->info(sprintf('[Add] Adding Main Product [%s]', $sku));

        $_product = $this->_productRepository->get($sku);
        /* @var $_product \Magento\Catalog\Model\Product */

        $_product->setPrice(10);
        $_product->setSpecialPrice(12);
        $_product->setCustomPrice(11);
        $_product->setOriginalCustomPrice(13);

        /*$mainItem = $quote->getItemByProduct($_product);
        /* @var $mainItem \Magento\Quote\Model\Quote\Item * */


        $this->_logger->info(
            sprintf('[Rem] OLD Quote Item [%s] [%s] [%s] [%s] [%s] [%s] ', $parentItem->getSku(), $parentItem->getQty(), $parentItem->getPrice(), $parentItem->getBasePrice(), $parentItem->getBasePriceInclTax(), $parentItem->getBaseTaxAmount())
            );

        // NEW Quote Item [GA150MO] [1] [124.96] [124.96] [149.95] [24.99]  [] []



        $quote->addProduct($_product, $parentItem->getQty());
        $mainItem = $quote->getItemByProduct($_product);
        $mainItem->setBasePrice($parentItem->getBasePrice());
        $mainItem->setBasePriceInclTax($parentItem->getBasePriceInclTax());
        $mainItem->setPrice($parentItem->getPrice());
        $mainItem->setBaseTaxAmount($parentItem->getBaseTaxAmount());
        $mainItem->setCustomPrice($parentItem->getPrice());
        $mainItem->setOriginalCustomPrice($parentItem->getPrice());
        $mainItem->getProduct()->setIsSuperMode(TRUE);



        //$mainItem->save();

        $this->_logger->info(
            sprintf('[Add1] NEW Quote Item [%s] [%s] [%s] [%s] [%s] [%s]', $mainItem->getSku(), $mainItem->getQty(), $mainItem->getPrice(), $mainItem->getBasePrice(), $mainItem->getBasePriceInclTax(), $mainItem->getBaseTaxAmount())
        );
        $this->_logger->info(
            sprintf('[Add2] NEW Quote Item [%s] [%s] [%s] [%s] [%s] [%s]', $_product->getSku(), $_product->getQty(), $_product->getPrice(), $_product->getBasePrice(), $_product->getBasePriceInclTax(), $_product->getBaseTaxAmount())
        );

        //$quote->collectTotals()->save();

        $this->_logger->info(
            sprintf('[Rem] Removing [%s]', $parentItem->getSku())
        );

        $quote->getItemById($parentItem->getId())->delete();

        $quote->collectTotals();

    }

    /**
     * @param   \Magento\Quote\Model\Quote\
     * @param   \Magento\Quote\Model\Quote\Item
     * @param   string
     * @return  void
     */

    protected function _addExtraItemToQuote(Quote $quote, Item $parentItem, $sku)
    {
        $this->_logger->info(sprintf('[Add] Extra Item [%s]', $sku));


         try {
            /**
             * known bug with case insensitivity
             * https://github.com/magento/magento2/issues/12073
             */
            $_product = $this->_productRepository->get($sku);
            //$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            //$_product = $objectManager->get('Magento\Catalog\Model\Product');
            /* @var $_product \Magento\Catalog\Model\Product */
            //$productId = $_product->getIdBySku($sku);
            //$_product->load($productId); // deprecated, but solves case sensitivity issue above.
            $_product->setPrice(0);
            $_product->setSpecialPrice(0);

            /**
             *  Not already added by a parent product
             */
            $item = $quote->getItemByProduct($_product);
            /* @var $item \Magento\Quote\Model\Quote\Item */

            /**
             * Add the item, it's not already been added.
             */
            if($item === FALSE) {
                $this->_logger->info(
                    sprintf('[Add] NEW Quote Item [%s] [%s]', $sku, $parentItem->getQty())
                );
                $quote->addProduct($_product, $parentItem->getQty());
                $item = $quote->getItemByProduct($_product);
                $item->getProduct()->setIsSuperMode(TRUE);
                $item->setBasePrice(0);
                $item->setBasePriceInclTax(0);
                $item->setPrice(0);
                $item->setBaseTaxAmount(0);
                $item->setCustomPrice(0);
                $item->setOriginalCustomPrice(0);
            }
            /**
             * Already added from another product
             */
            else {
                $newQty = $item->getQty() + $parentItem->getQty();
                $this->_logger->info(
                    sprintf('[UPDATE] Quote Item [%s] [%s]', $sku, $newQty)
                );
                //$item->addQty($item->getQty());
                $item->setQty($newQty);
            }

            $item->save();
            $quote->save();
        }
        catch(NoSuchEntityException $ex) {
            $this->_logger->error("Extra Product Doesnt Exist", [$sku]);
        }
        catch(\Exception $ex) {
            $this->_logger->error("[Error] adding item", [$ex->getMessage()]);
        }
    }

    /**
     *
     * @param   ProductInterface $product
     * @return  bool
     */
    protected function _hasExtraProducts(ProductInterface $product)
    {
        /* @var $product \Magento\Catalog\Model\Product */

        return $product->getHasExtraItems() == 1;
    }


    /**
     * @param   ProductInterface $product
     * @return  array
     */
    protected function _getExtraProducts(ProductInterface $product)
    {
        /* @var $product \Magento\Catalog\Model\Product */

        if(empty($product->getExtraItemsList())) {
            return [];
        }

        return explode(',', $product->getExtraItemsList());
    }

    /** MAIN PRODUCT replacment */

    /**
     *
     * @param   ProductInterface $product
     * @return  bool
     */
    protected function _getReplaceMainProduct(ProductInterface $product)
    {
        /* @var $product \Magento\Catalog\Model\Product */

        return $product->getReplaceMainProduct() == 1;
    }

    /**
     * @param   ProductInterface $product
     * @return  string
     */
    protected function _getMainProductSku(ProductInterface $product)
    {
        /* @var $product \Magento\Catalog\Model\Product */
        return $product->getMainProductSku() ? $product->getMainProductSku() : "";
    }
}
