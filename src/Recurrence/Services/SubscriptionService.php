<?php

namespace Mundipagg\Core\Recurrence\Services;

use Mundipagg\Core\Kernel\Abstractions\AbstractModuleCoreSetup as MPSetup;
use Mundipagg\Core\Kernel\Factories\OrderFactory;
use Mundipagg\Core\Kernel\Interfaces\PlatformOrderInterface;
use Mundipagg\Core\Kernel\Services\APIService;
use Mundipagg\Core\Kernel\Services\LocalizationService;
use Mundipagg\Core\Kernel\Services\MoneyService;
use Mundipagg\Core\Kernel\Services\OrderLogService;
use Mundipagg\Core\Kernel\Services\OrderService;
use Mundipagg\Core\Kernel\ValueObjects\OrderState;
use Mundipagg\Core\Kernel\ValueObjects\OrderStatus;
use Mundipagg\Core\Payment\Aggregates\Customer;
use Mundipagg\Core\Payment\Aggregates\Order;
use Mundipagg\Core\Payment\Aggregates\Order as PaymentOrder;
use Mundipagg\Core\Payment\Services\ResponseHandlers\ErrorExceptionHandler;
use Mundipagg\Core\Payment\ValueObjects\CustomerType;
use Mundipagg\Core\Recurrence\Aggregates\SubProduct;
use Mundipagg\Core\Recurrence\Aggregates\Subscription;
use Mundipagg\Core\Recurrence\Factories\SubProductFactory;
use Mundipagg\Core\Recurrence\ValueObjects\IntervalValueObject;
use Mundipagg\Core\Recurrence\ValueObjects\PricingSchemeValueObject as PricingScheme;
use MundiPagg\MundiPagg\Model\Source\Interval;

final class SubscriptionService
{
    private $logService;

    public function __construct()
    {
        $this->logService = new OrderLogService();
    }

    public function createSubscriptionAtMundipagg(PlatformOrderInterface $platformOrder)
    {
        try {
            $orderService = new OrderService();
            $orderInfo = $orderService->getOrderInfo($platformOrder);

            $this->logService->orderInfo(
                $platformOrder->getCode(),
                'Creating order.',
                $orderInfo
            );
            //set pending
            $platformOrder->setState(OrderState::stateNew());
            $platformOrder->setStatus(OrderStatus::pending());

            //build PaymentOrder based on platformOrder
            $order = $orderService->extractPaymentOrderFromPlatformOrder($platformOrder);
            $subscription = $this->extractSubscriptionDataFromOrder($order);

            //Send through the APIService to mundipagg
            $apiService = new APIService();
            $response = $apiService->createSubscription($subscription);

            /*if ($this->checkResponseStatus($response)) {
                $i18n = new LocalizationService();
                $message = $i18n->getDashboard("Can't create order.");

                throw new \Exception($message, 400);
            }

            $platformOrder->save();

            $orderFactory = new OrderFactory();
            $response = $orderFactory->createFromPostData($response);

            $response->setPlatformOrder($platformOrder);

            $handler = $this->getResponseHandler($response);
            $handler->handle($response, $order);

            $platformOrder->save();*/

            return [$response];
        } catch(\Exception $e) {
            $exceptionHandler = new ErrorExceptionHandler;
            $paymentOrder = new PaymentOrder;
            $paymentOrder->setCode($platformOrder->getcode());
            $frontMessage = $exceptionHandler->handle($e, $paymentOrder);
            throw new \Exception($frontMessage, 400);
        }
    }

    private function extractSubscriptionDataFromOrder(PaymentOrder $order)
    {
        $subscription = new Subscription();

        $items = $this->getSubscriptionItems($order);

        if (count($items) == 0) {
            return;
        }

        $recurrenceSettings = $items[0];
        $payments = $order->getPayments();
        $cardToken = $order->getPayments()[0]->getIdentifier()->getValue();
        $intervalType = $recurrenceSettings->getRepetitions()[0]->getInterval();
        $intervalCount = $recurrenceSettings->getRepetitions()[0]->getIntervalCount();

        $subscription->setCode($order->getCode());
        $subscription->setPaymentMethod($order->getPaymentMethod());
        $subscription->setIntervalType($intervalType);
        $subscription->setIntervalCount($intervalCount);

        $subscriptionItems = $this->extractSubscriptionItemsFromOrder(
            $order,
            $recurrenceSettings
        );
        $subscription->setItems($subscriptionItems);
        $subscription->setCardToken($cardToken);
        $subscription->setBillingType($recurrenceSettings->getBillingType());
        $subscription->setCustomer($order->getCustomer());


        /** @fixme Fix installments and boleto */
        $subscription->setInstallments(1);
        $subscription->setBoletoDays(5);

        return $subscription;
    }

    private function getSubscriptionItems(PaymentOrder $order)
    {
        $recurrenceService = new RecurrenceService();
        $items = [];

        foreach ($order->getItems() as $product) {
            $items[] =
                $recurrenceService
                    ->getRecurrenceProductByProductId(
                        $product->getCode()
                    );
        }

        return $items;
    }

    private function extractSubscriptionItemsFromOrder($order, $recurrenceSettings)
    {
        $subscriptionItems = [];

        foreach ($order->getItems() as $item) {
            $subProduct = new SubProduct();

            $subProduct->setCycles($recurrenceSettings->getCycles());
            $subProduct->setDescription($item->getDescription());
            $subProduct->setQuantity($item->getQuantity());
            $pricingScheme = PricingScheme::UNIT($item->getAmount());
            $subProduct->setPricingScheme($pricingScheme);
            $subscriptionItems[] = $subProduct;
        }

        return $subscriptionItems;

        /*$subscriptionItems = [
            'description' => $item->getDescription(),
            "quantity" => $item->getQuantity(),
            "pricing_scheme" => [
                "price" => $item->getAmount()
            ],
            "cycles" => $recurrenceSettings->getCycles()
        ];*/


    }


}