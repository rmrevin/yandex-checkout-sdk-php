<?php

namespace Tests\YandexCheckout\Request\Payments;

use PHPUnit\Framework\TestCase;
use YandexCheckout\Helpers\Random;
use YandexCheckout\Model\Airline;
use YandexCheckout\Model\ConfirmationAttributes\ConfirmationAttributesExternal;
use YandexCheckout\Model\ConfirmationAttributes\ConfirmationAttributesRedirect;
use YandexCheckout\Model\ConfirmationType;
use YandexCheckout\Model\CurrencyCode;
use YandexCheckout\Model\Leg;
use YandexCheckout\Model\Passenger;
use YandexCheckout\Model\PaymentData\B2b\Sberbank\VatDataRate;
use YandexCheckout\Model\PaymentData\B2b\Sberbank\VatDataType;
use YandexCheckout\Model\PaymentData\PaymentDataAlfabank;
use YandexCheckout\Model\PaymentData\PaymentDataB2bSberbank;
use YandexCheckout\Model\PaymentData\PaymentDataGooglePay;
use YandexCheckout\Model\PaymentData\PaymentDataApplePay;
use YandexCheckout\Model\PaymentData\PaymentDataBankCard;
use YandexCheckout\Model\PaymentData\PaymentDataBankCardCard;
use YandexCheckout\Model\PaymentData\PaymentDataMobileBalance;
use YandexCheckout\Model\PaymentData\PaymentDataInstallments;
use YandexCheckout\Model\PaymentData\PaymentDataQiwi;
use YandexCheckout\Model\PaymentData\PaymentDataSberbank;
use YandexCheckout\Model\PaymentData\PaymentDataWebmoney;
use YandexCheckout\Model\PaymentData\PaymentDataYandexWallet;
use YandexCheckout\Model\PaymentMethodType;
use YandexCheckout\Model\Receipt\PaymentMode;
use YandexCheckout\Model\Receipt\PaymentSubject;
use YandexCheckout\Request\Payments\CreatePaymentRequest;
use YandexCheckout\Request\Payments\CreatePaymentRequestSerializer;

class CreatePaymentRequestSerializerTest extends TestCase
{
    private $fieldMap = array(
        'payment_token'     => 'paymentToken',
        'payment_method_id' => 'paymentMethodId',
        'client_ip'         => 'clientIp',
    );

    /**
     * @dataProvider validDataProvider
     * @param $options
     */
    public function testSerialize($options)
    {
        $serializer = new CreatePaymentRequestSerializer();
        $instance   = CreatePaymentRequest::builder()->build($options);
        $data       = $serializer->serialize($instance);

        $expected = array(
            'amount' => array(
                'value'    => $options['amount'],
                'currency' => isset($options['currency']) ? $options['currency'] : CurrencyCode::RUB,
            ),
        );
        foreach ($this->fieldMap as $mapped => $field) {
            if (isset($options[$field])) {
                $value = $options[$field];
                if (!empty($value)) {
                    $expected[$mapped] = $value instanceof \DateTime ? $value->format(DATE_ATOM) : $value;
                }
            }
        }
        if (!empty($options['accountId']) && !empty($options['gatewayId'])) {
            $expected['recipient'] = array(
                'account_id' => $options['accountId'],
                'gateway_id' => $options['gatewayId'],
            );
        }
        if (!empty($options['confirmation'])) {
            $expected['confirmation'] = array(
                'type' => $options['confirmation']->getType(),
            );
            if ($options['confirmation']->getType() === ConfirmationType::REDIRECT) {
                $expected['confirmation']['enforce']    = $options['confirmation']->enforce;
                $expected['confirmation']['return_url'] = $options['confirmation']->returnUrl;
            }
        }
        if (!empty($options['paymentMethodData'])) {
            $expected['payment_method_data'] = array(
                'type' => $options['paymentMethodData']->getType(),
            );
            switch ($options['paymentMethodData']['type']) {
                case PaymentMethodType::ALFABANK:
                    $expected['payment_method_data']['login'] = $options['paymentMethodData']->getLogin();
                    break;
                case PaymentMethodType::APPLE_PAY:
                    $expected['payment_method_data']['payment_data'] = $options['paymentMethodData']->getPaymentData();
                    break;
                case PaymentMethodType::GOOGLE_PAY:
                    $expected['payment_method_data']['payment_method_token'] = $options['paymentMethodData']->getPaymentMethodToken();
                    $expected['payment_method_data']['google_transaction_id'] = $options['paymentMethodData']->getGoogleTransactionId();
                    break;
                case PaymentMethodType::BANK_CARD:
                    $expected['payment_method_data']['card'] = array(
                        'number'       => $options['paymentMethodData']->getCard()->getNumber(),
                        'expiry_year'  => $options['paymentMethodData']->getCard()->getExpiryYear(),
                        'expiry_month' => $options['paymentMethodData']->getCard()->getExpiryMonth(),
                        'csc'          => $options['paymentMethodData']->getCard()->getCsc(),
                        'cardholder'   => $options['paymentMethodData']->getCard()->getCardholder(),
                    );
                    break;
                case PaymentMethodType::MOBILE_BALANCE:
                case PaymentMethodType::CASH:
                    $expected['payment_method_data']['phone'] = $options['paymentMethodData']->getPhone();
                    break;
                case PaymentMethodType::SBERBANK:
                    $expected['payment_method_data']['phone'] = $options['paymentMethodData']->getPhone();
                    break;
                case PaymentMethodType::B2B_SBERBANK:
                    /** @var PaymentDataB2bSberbank $paymentMethodData */
                    $paymentMethodData                                  = $options['paymentMethodData'];
                    $expected['payment_method_data']['payment_purpose'] = $paymentMethodData->getPaymentPurpose();
                    $expected['payment_method_data']['vat_data']        = array(
                        'type'   => $paymentMethodData->getVatData()->getType(),
                        'rate'   => $paymentMethodData->getVatData()->getRate(),
                        'amount' => array(
                            'value'    => $paymentMethodData->getVatData()->getAmount()->getValue(),
                            'currency' => $paymentMethodData->getVatData()->getAmount()->getCurrency(),
                        ),
                    );
                    break;
            }
        }
        if (!empty($options['metadata'])) {
            $expected['metadata'] = array();
            foreach ($options['metadata'] as $key => $value) {
                $expected['metadata'][$key] = $value;
            }
        }
        if (!empty($options['receiptItems'])) {
            foreach ($options['receiptItems'] as $item) {
                $expected['receipt']['items'][] = array(
                    'description' => $item['title'],
                    'quantity'    => empty($item['quantity']) ? 1 : $item['quantity'],
                    'amount'      => array(
                        'value'    => $item['price'],
                        'currency' => isset($options['currency']) ? $options['currency'] : CurrencyCode::RUB,
                    ),
                    'vat_code'    => $item['vatCode'],
                    'payment_subject' => PaymentSubject::COMMODITY,
                    'payment_mode' => PaymentMode::PARTIAL_PREPAYMENT
                );
            }
        }
        if (!empty($options['receiptEmail'])) {
            $expected['receipt']['email'] = $options['receiptEmail'];
        }
        if (!empty($options['receiptPhone'])) {
            $expected['receipt']['phone'] = $options['receiptPhone'];
        }
        if (!empty($options['taxSystemCode'])) {
            $expected['receipt']['tax_system_code'] = $options['taxSystemCode'];
        }
        if (array_key_exists('capture', $options)) {
            $expected['capture'] = (bool)$options['capture'];
        }
        if (array_key_exists('savePaymentMethod', $options)) {
            $expected['save_payment_method'] = (bool)$options['savePaymentMethod'];
        }
        if (!empty($options['description'])) {
            $expected['description'] = $options['description'];
        }
        if (!empty($options['airline'])) {
            $expected['airline'] = array(
                'booking_reference' => $options['airline']['booking_reference'],
                'ticket_number'     => $options['airline']['ticket_number'],
                'passengers'        => array_map(function ($passenger) {
                    return array(
                        'first_name' => $passenger['first_name'],
                        'last_name'  => $passenger['last_name'],
                    );
                }, $options['airline']['passengers']),
                'legs'              => array_map(function ($leg) {
                    return array(
                        'departure_airport'   => $leg['departure_airport'],
                        'destination_airport' => $leg['destination_airport'],
                        'departure_date'      => $leg['departure_date'],
                    );
                }, $options['airline']['legs']),
            );
        }

        self::assertEquals($expected, $data);
    }

    public function validDataProvider()
    {
        $airline = new Airline();
        $airline->setBookingReference(Random::str(10));
        $airline->setTicketNumber(Random::int(10));
        $leg = new Leg();
        $leg->setDepartureAirport(Random::str(3, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'));
        $leg->setDestinationAirport(Random::str(3, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'));
        $leg->setDepartureDate("2018-12-31");
        $airline->setLegs(array($leg));
        $passenger = new Passenger();
        $passenger->setFirstName(Random::str(10));
        $passenger->setLastName(Random::str(10));
        $airline->setPassengers(array($passenger));

        $result = array(
            array(
                array(
                    'amount'        => mt_rand(10, 100000),
                    'paymentToken'  => Random::str(36),
                    'receiptItems'  => array(
                        array(
                            'title'    => Random::str(10),
                            'quantity' => Random::int(1, 10),
                            'price'    => Random::int(100, 100),
                            'vatCode'  => Random::int(1, 6),
                        ),
                    ),
                    'receiptEmail'  => Random::str(10),
                    'taxSystemCode' => Random::int(1, 6),
                    'description'   => Random::str(10),
                    'airline'       => array(
                        'booking_reference' => Random::str(10),
                        'ticket_number'     => Random::int(10),
                        'passengers'        => array(
                            array(
                                'first_name' => Random::str(10, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'),
                                'last_name'  => Random::str(10, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'),
                            )
                        ),
                        'legs'              => array(
                            array(
                                'departure_airport'   => Random::str(3, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'),
                                'destination_airport' => Random::str(3, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'),
                                'departure_date'      => "2020-01-01",
                            )
                        ),
                    ),
                ),
            ),
        );
        $confirmations = array(
            new ConfirmationAttributesExternal(),
            new ConfirmationAttributesRedirect(),
        );
        $paymentData   = array(
            new PaymentDataAlfabank(),
            new PaymentDataApplePay(),
            new PaymentDataGooglePay(),
            new PaymentDataBankCard(),
            new PaymentDataMobileBalance(),
            new PaymentDataQiwi(),
            new PaymentDataSberbank(),
            new PaymentDataWebmoney(),
            new PaymentDataYandexWallet(),
            new PaymentDataInstallments(),
            new PaymentDataB2bSberbank(),
        );
        $paymentData[0]->setLogin(Random::str(10));

        $paymentData[1]->setPaymentData(Random::str(10));
        $paymentData[2]->setPaymentMethodToken(Random::str(10));
        $paymentData[2]->setGoogleTransactionId(Random::str(10));

        $card = new PaymentDataBankCardCard();
        $card->setNumber(Random::str(16, '0123456789'));
        $card->setExpiryYear(Random::int(2000, 2200));
        $card->setExpiryMonth(Random::value(array('01', '02', '03', '04', '05', '06', '07', '08', '09', '11', '12')));
        $card->setCsc(Random::str(4, '0123456789'));
        $card->setCardholder(Random::str(26, 'abcdefghijklmnopqrstuvwxyz'));
        $paymentData[3]->setCard($card);
        $paymentData[4]->setPhone(Random::str(14, '0123456789'));

        $paymentData[6]->setPhone(Random::str(14, '0123456789'));

        /** @var PaymentDataB2bSberbank $paymentData[10] */
        $paymentDataB2bSberbank = new PaymentDataB2bSberbank();
        $paymentDataB2bSberbank->setPaymentPurpose(Random::str(10));
        $paymentDataB2bSberbank->setVatData(array(
            'type'   => VatDataType::CALCULATED,
            'rate'   => VatDataRate::RATE_10,
            'amount' => array(
                'value'    => Random::int(1, 10000),
                'currency' => CurrencyCode::USD,
            ),
        ));
        $paymentData[10] = $paymentDataB2bSberbank;

        $confirmations[1]->setEnforce(true);
        $confirmations[1]->setReturnUrl(Random::str(10));
        foreach($paymentData as $i => $paymentMethodData) {
            $request  = array(
                'accountId'         => uniqid(),
                'gatewayId'         => uniqid(),
                'amount'            => mt_rand(0, 100000),
                'currency'          => CurrencyCode::RUB,
                'referenceId'       => uniqid(),
                'paymentMethodData' => $paymentData[$i],
                'confirmation'      => Random::value($confirmations),
                'savePaymentMethod' => Random::bool(),
                'capture'           => mt_rand(0, 1) ? true : false,
                'clientIp'          => long2ip(mt_rand(0, pow(2, 32))),
                'metadata'          => array('test' => uniqid()),
                'receiptItems'      => $this->getReceipt($i + 1),
                'receiptEmail'      => Random::str(10),
                'receiptPhone'      => Random::str(12, '0123456789'),
                'taxSystemCode'     => Random::int(1, 6),
                'airline'           => $airline,
            );
            $result[] = array($request);
        }
        return $result;
    }

    private function getReceipt($count)
    {
        $result = array();
        for ($i = 0; $i < $count; $i++) {
            $result[] = array(
                'title'    => Random::str(10),
                'quantity' => Random::float(1, 100),
                'price'    => Random::int(1, 100),
                'vatCode'  => Random::int(1, 6),
            );
        }
        return $result;
    }
}