<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\SalesChannel\Price;

use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceDefinitionCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Price\Struct\ReferencePriceDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceCollection;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceRuleEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ProductPriceDefinitionBuilder implements ProductPriceDefinitionBuilderInterface
{
    public function build(ProductEntity $product, SalesChannelContext $context, int $quantity = 1): ProductPriceDefinitions
    {
        $listingPrice = $this->buildListingPriceDefinition($product, $context);

        return new ProductPriceDefinitions(
            $this->buildPriceDefinition($product, $context),
            $this->buildPriceDefinitions($product, $context),
            $listingPrice['from'],
            $listingPrice['to'],
            $this->buildPriceDefinitionForQuantity($product, $context, $quantity)
        );
    }

    private function buildPriceDefinitions(ProductEntity $product, SalesChannelContext $context): PriceDefinitionCollection
    {
        $taxRules = $context->buildTaxRules($product->getTaxId());

        $prices = $this->getFirstMatchingPriceRule($product->getPrices(), $context);

        if (!$prices) {
            return new PriceDefinitionCollection();
        }

        $prices = $this->sortByQuantity($prices);

        $definitions = [];

        $reference = $this->buildReferencePriceDefinition($product);

        foreach ($prices as $price) {
            $quantity = $price->getQuantityEnd() ?? $price->getQuantityStart();

            $definition = new QuantityPriceDefinition($this->getCurrencyPrice($price, $context), $taxRules, $quantity);
            $definition->setReferencePriceDefinition($reference);
            $definition->setListPrice($this->getListPrice($price->getPrice(), $context));
            $definitions[] = $definition;
        }

        return new PriceDefinitionCollection($definitions);
    }

    private function buildPriceDefinition(ProductEntity $product, SalesChannelContext $context): QuantityPriceDefinition
    {
        $price = $this->getProductCurrencyPrice($product, $context);

        $list = $this->getListPrice($product->getPrice(), $context);
        $reference = $this->buildReferencePriceDefinition($product);

        $definition = new QuantityPriceDefinition($price, $context->buildTaxRules($product->getTaxId()));
        $definition->setReferencePriceDefinition($reference);
        $definition->setListPrice($list);

        return $definition;
    }

    private function buildListingPriceDefinition(ProductEntity $product, SalesChannelContext $context): array
    {
        $taxRules = $context->buildTaxRules($product->getTaxId());

        $reference = $this->buildReferencePriceDefinition($product);

        if ($product->getListingPrices()) {
            $listingPrice = $product->getListingPrices()->getContextPrice($context->getContext());

            if ($listingPrice) {
                // indexed listing prices are indexed for each currency
                $from = $this->getPriceForTaxState($listingPrice->getFrom(), $context);
                $to = $this->getPriceForTaxState($listingPrice->getTo(), $context);

                if ($listingPrice->getCurrencyId() !== $context->getContext()->getCurrencyId()) {
                    $from *= $context->getContext()->getCurrencyFactor();
                    $to *= $context->getContext()->getCurrencyFactor();
                }

                $from = new QuantityPriceDefinition($from, $taxRules);
                $from->setReferencePriceDefinition($reference);

                $to = new QuantityPriceDefinition($to, $taxRules);
                $to->setReferencePriceDefinition($reference);

                return ['from' => $from, 'to' => $to];
            }
        }

        $prices = $this->getFirstMatchingPriceRule($product->getPrices(), $context);

        if ($prices === null) {
            $price = $this->getProductCurrencyPrice($product, $context);

            $definition = new QuantityPriceDefinition($price, $taxRules);
            $definition->setReferencePriceDefinition($reference);

            return ['from' => $definition, 'to' => $definition];
        }

        $highest = $this->getCurrencyPrice($prices[0], $context);
        $lowest = $highest;

        foreach ($prices as $price) {
            $value = $this->getCurrencyPrice($price, $context);

            $highest = $value > $highest ? $value : $highest;
            $lowest = $value < $lowest ? $value : $lowest;
        }

        $from = new QuantityPriceDefinition($lowest, $taxRules);
        $from->setReferencePriceDefinition($reference);

        $to = new QuantityPriceDefinition($highest, $taxRules);
        $to->setReferencePriceDefinition($reference);

        return ['from' => $from, 'to' => $to];
    }

    private function buildPriceDefinitionForQuantity(ProductEntity $product, SalesChannelContext $context, int $quantity): QuantityPriceDefinition
    {
        $taxRules = $context->buildTaxRules($product->getTaxId());

        /** @var ProductPriceEntity[]|null $prices */
        $prices = $this->getFirstMatchingPriceRule($product->getPrices(), $context);

        if (!$prices) {
            $price = $this->getProductCurrencyPrice($product, $context);

            $definition = new QuantityPriceDefinition($price, $taxRules, $quantity);

            $definition->setListPrice(
                $this->getListPrice($product->getPrice(), $context)
            );

            $definition->setReferencePriceDefinition(
                $this->buildReferencePriceDefinition($product)
            );

            return $definition;
        }

        $prices = $this->getQuantityPrices($prices, $quantity);

        $definition = new QuantityPriceDefinition($this->getCurrencyPrice($prices[0], $context), $taxRules, $quantity);

        $definition->setListPrice(
            $this->getListPrice($prices[0]->getPrice(), $context)
        );

        $definition->setReferencePriceDefinition($this->buildReferencePriceDefinition($product));

        return $definition;
    }

    private function getQuantityPrices(array $prices, int $quantity): array
    {
        $filtered = [];

        /** @var ProductPriceEntity $price */
        foreach ($prices as $price) {
            $end = $price->getQuantityEnd() ?? $quantity + 1;

            if ($price->getQuantityStart() <= $quantity && $end >= $quantity) {
                $filtered[] = $price;
            }
        }

        return $filtered;
    }

    private function getFirstMatchingPriceRule(ProductPriceCollection $rules, SalesChannelContext $context): ?array
    {
        foreach ($context->getRuleIds() as $ruleId) {
            $filtered = $this->filterByRuleId($rules->getElements(), $ruleId);

            if (\count($filtered) > 0) {
                return $filtered;
            }
        }

        return null;
    }

    private function filterByRuleId(array $rules, string $ruleId): array
    {
        $filtered = [];
        /** @var PriceRuleEntity $priceRule */
        foreach ($rules as $priceRule) {
            if ($priceRule->getRuleId() === $ruleId) {
                $filtered[] = $priceRule;
            }
        }

        return $filtered;
    }

    private function getCurrencyPrice(PriceRuleEntity $rule, SalesChannelContext $context): float
    {
        $price = $rule->getPrice()->getCurrencyPrice($context->getCurrency()->getId());

        $value = $this->getPriceForTaxState($price, $context);

        if ($price->getCurrencyId() === Defaults::CURRENCY) {
            $value *= $context->getContext()->getCurrencyFactor();
        }

        return $value;
    }

    private function getPriceForTaxState(Price $price, SalesChannelContext $context): float
    {
        if ($context->getTaxState() === CartPrice::TAX_STATE_GROSS) {
            return $price->getGross();
        }

        return $price->getNet();
    }

    private function sortByQuantity(array $prices): array
    {
        usort($prices, function (ProductPriceEntity $a, ProductPriceEntity $b) {
            return $a->getQuantityStart() <=> $b->getQuantityStart();
        });

        return $prices;
    }

    private function buildReferencePriceDefinition(ProductEntity $product): ?ReferencePriceDefinition
    {
        $referencePrice = null;
        if (
            $product->getPurchaseUnit()
            && $product->getReferenceUnit()
            && $product->getUnit() !== null
            && $product->getPurchaseUnit() !== $product->getReferenceUnit()
        ) {
            $referencePrice = new ReferencePriceDefinition(
                $product->getPurchaseUnit(),
                $product->getReferenceUnit(),
                (string) $product->getUnit()->getTranslation('name')
            );
        }

        return $referencePrice;
    }

    private function getListPrice(?PriceCollection $prices, SalesChannelContext $context): ?float
    {
        if (!$prices) {
            return null;
        }

        $price = $prices->getCurrencyPrice($context->getCurrency()->getId());
        if (!$price || !$price->getListPrice()) {
            return null;
        }
        if ($context->getTaxState() === CartPrice::TAX_STATE_GROSS) {
            $value = $price->getListPrice()->getGross();
        } else {
            $value = $price->getListPrice()->getNet();
        }

        if ($price->getCurrencyId() !== $context->getCurrency()->getId()) {
            $value *= $context->getContext()->getCurrencyFactor();
        }

        return $value;
    }

    private function getProductCurrencyPrice(ProductEntity $product, SalesChannelContext $context): float
    {
        $price = $product->getPrice()->getCurrencyPrice($context->getCurrency()->getId());

        if (!$price) {
            return 0.0;
        }

        $value = $this->getPriceForTaxState($price, $context);

        if ($price->getCurrencyId() !== $context->getCurrency()->getId()) {
            $value *= $context->getContext()->getCurrencyFactor();
        }

        return $value;
    }
}
