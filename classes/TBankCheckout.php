<?php namespace Wpstudio\TBank\Classes;

use OFFLINE\Mall\Classes\Payments\PaymentProvider;
use OFFLINE\Mall\Classes\Payments\PaymentResult;
use OFFLINE\Mall\Models\OrderProduct;
use OFFLINE\Mall\Models\PaymentGatewaySettings;
use OFFLINE\Mall\Models\OrderState;
use OFFLINE\Mall\Models\Order;
use Omnipay\Common\Exception\InvalidResponseException;
use Omnipay\Omnipay;
use Omnipay\TBank\Gateway;
use Omnipay\TBank\Message\AbstractRequest;
use Omnipay\TBank\Message\AbstractResponse;
use Omnipay\TBank\Message\AuthorizeRequest;
use Omnipay\TBank\Message\AuthorizeResponse;
use Throwable;
use Session;
use Lang;


class TBankCheckout extends PaymentProvider
{
    /**
     * The order that is being paid.
     *
     * @var Order
     */
    public $order;
    /**
     * Data that is needed for the payment.
     * Card numbers, tokens, etc.
     *
     * @var array
     */
    public $data;

    /**
     * Return the display name of your payment provider.
     *
     * @return string
     */
    public function name(): string
    {
        return Lang::get('wpstudio.tbank::lang.settings.tbank_checkout');
    }

    /**
     * Return a unique identifier for this payment provider.
     *
     * @return string
     */
    public function identifier(): string
    {
        return 'tbank';
    }

    /**
     * Validate the given input data for this payment.
     *
     * @return bool
     * @throws \October\Rain\Exception\ValidationException
     */
    public function validate(): bool
    {
        return true;
    }


    /**
     * Process the payment.
     *
     * @param PaymentResult $result
     *
     * @return PaymentResult
     */
    public function process(PaymentResult $result): PaymentResult
    {
        $gateway = $this->getGateway();

        try {
            $request = $gateway->authorize([
                'OrderId' => $this->order->id,
                'Amount' => $this->order->total_in_currency * 100,
                'SuccessURL' => $this->returnUrl(),
                'FailURL'     => $this->cancelUrl(),
//                'DATA' => $this->getCustomerData(),
                'Description'   => Lang::get('wpstudio.tbank::lang.messages.order_number').$this->order->order_number,
            ]);

            assert($request instanceof AuthorizeRequest);

            $request->setReceipt($this->getReceipt());

            $response = $request->send();

            if (!$response->isSuccessful()) {
                throw new InvalidResponseException();
            }
        } catch (Throwable $e) {
            return $result->fail([], $e);
        }

        assert($response instanceof AuthorizeResponse);

        Session::put('mall.payment.callback', self::class);

        $this->setOrder($result->order);

        $result->order->payment_transaction_id = $response->getPaymentId();

        $result->order->save();

        return $result->redirect($response->getRedirectResponse()->getTargetUrl());
    }

    private function getCustomerData()
    {
        return [
            'Email' => $this->order->customer->user->email,
            'Phone' => $this->order->customer->user->phone,
        ];
    }

    /**
     * Generate tbank Receipt
     * @see https://www.tbank.ru/kassa/dev/payments/index.html#tag/Standartnyj-platezh/operation/Init
     *
     * @return array
     *
     */
    public function getReceipt()
    {
        return array_merge($this->getCustomerData(), [
            'Taxation' => 'osn',
            'Items' => $this->getReceiptItems()
        ]);
    }

    /**
     * Create order cartitems for order bundle
     *
     * @return array
     */
    public function getReceiptItems()
    {
        return $this->order->products->map(fn(OrderProduct $product) => [
            'Name' => $product->name,
            'Quantity' => $product->quantity,
            'ShopCode' => $product->variant_id ?? $product->product_id,
            'Price' => $product->pricePostTaxes()->integer,
            'Amount' => $product->pricePostTaxes()->integer * $product->quantity,
            'Tax' => 'none',
        ])->toArray();
    }

    /**
     * Y.K. has processed the payment and redirected the user back.
     *
     * @param PaymentResult $result
     *
     * @return PaymentResult
     */
    public function complete(PaymentResult $result): PaymentResult
    {
        $this->setOrder($result->order);

        $gateway = $this->getGateway();

        try {
            /**
             * It will be similar to calling methods `completeAuthorize()` and `completePurchase()`
             */
            $response = $gateway->orderStatus(
                [
                    'PaymentId' => $result->order->payment_transaction_id,
                ]
            )->send();
        } catch (Throwable $e) {
            return $result->fail([], $e);
        }

        assert($response instanceof AbstractResponse);

        $data = (array)$response->getData();

        if ( ! $response->isSuccessful()) {
            return $result->fail($data, $response);
        }

        return $result->success($data, $response);
    }

    /**
     * Build the Omnipay Gateway for PayPal.
     *
     * @return \Omnipay\Common\GatewayInterface
     */
    protected function getGateway()
    {
        $gateway = Omnipay::create('TBank');

        assert($gateway instanceof Gateway);

        $gateway->setTerminalKey(PaymentGatewaySettings::get('TerminalKey'));
        $gateway->setPassword(PaymentGatewaySettings::get('Password'));

        if (PaymentGatewaySettings::get('tbank_test_mode')) {
            $gateway->setTestMode(true);
            $gateway->setTerminalKey(PaymentGatewaySettings::get('TerminalKeyTest'));
            $gateway->setPassword(PaymentGatewaySettings::get('PasswordTest'));
        }

        return $gateway;
    }

    /**
     * Return any custom backend settings fields.
     *
     * These fields will be rendered in the backend
     * settings page of your provider.
     *
     * @return array
     */
    public function settings(): array
    {
        return [
            'tbank_test_mode'     => [
                'label'   => 'wpstudio.tbank::lang.settings.tbank_test_mode',
                'comment' => 'wpstudio.tbank::lang.settings.tbank_test_mode_label',
                'span'    => 'left',
                'type'    => 'switch',
            ],
            'TerminalKeyTest'     => [
                'label'   => 'TerminalKeyTest',
                'span'    => 'left',
                'type'    => 'text',
            ],
            'PasswordTest' => [
                'label'   => 'PasswordTest',
                'span'    => 'left',
                'type'    => 'text',
            ],
            'TerminalKey'     => [
                'label'   => 'TerminalKey',
                'span'    => 'left',
                'type'    => 'text',
            ],
            'Password' => [
                'label'   => 'Password',
                'span'    => 'left',
                'type'    => 'text',
            ],
        ];
    }

    /**
     * Setting keys returned from this method are stored encrypted.
     *
     * Use this to store API tokens and other secret data
     * that is needed for this PaymentProvider to work.
     *
     * @return array
     */
    public function encryptedSettings(): array
    {
        return ['password'];
    }

    /**
     * Getting order state id by flag
     *
     * @param $orderStateFlag
     * @return int
     */
    protected function getOrderStateId($orderStateFlag): int
    {
        $orderStateModel = OrderState::where('flag', $orderStateFlag)->first();

        return $orderStateModel->id;
    }
}
