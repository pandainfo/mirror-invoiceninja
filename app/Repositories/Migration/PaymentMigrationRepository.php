<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://opensource.org/licenses/AAL
 */

namespace App\Repositories\Migration;

use App\Libraries\Currency\Conversion\CurrencyApi;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Repositories\ActivityRepository;
use App\Repositories\BaseRepository;
use App\Repositories\CreditRepository;
use App\Utils\Ninja;
use App\Utils\Traits\MakesHash;
use App\Utils\Traits\SavesDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * PaymentMigrationRepository.
 */
class PaymentMigrationRepository extends BaseRepository
{
    use MakesHash;
    use SavesDocuments;

    protected $credit_repo;

    protected $activity_repo;

    public function __construct(CreditRepository $credit_repo)
    {
        $this->credit_repo = $credit_repo;
        $this->activity_repo = new ActivityRepository();
    }

    /**
     * Saves and updates a payment. //todo refactor to handle refunds and payments.
     *
     * @param array $data the request object
     * @param Payment $payment The Payment object
     * @return Payment|null Payment $payment
     */
    public function save(array $data, Payment $payment): ?Payment
    {
        if ($payment->amount >= 0) {
            return $this->applyPayment($data, $payment);
        }

        return $payment;
    }

    /**
     * Handles a positive payment request.
     * @param  array $data      The data object
     * @param  Payment $payment The $payment entity
     * @return Payment          The updated/created payment object
     */
    private function applyPayment(array $data, Payment $payment): ?Payment
    {

        //check currencies here and fill the exchange rate data if necessary
        if (! $payment->id) {
            $this->processExchangeRates($data, $payment);

            /*We only update the paid to date ONCE per payment*/
            if (array_key_exists('invoices', $data) && is_array($data['invoices']) && count($data['invoices']) > 0) {
                if ($data['amount'] == '') {
                    $data['amount'] = array_sum(array_column($data['invoices'], 'amount'));
                }
            }
        }

        /*Fill the payment*/
        $payment->fill($data);
        //$payment->status_id = Payment::STATUS_COMPLETED;
        
        if (!array_key_exists('status_id', $data)) {
            info("payment with no status id?");
            info(print_r($data, 1));
        }

        $payment->status_id = $data['status_id'];
        $payment->deleted_at = $data['deleted_at'] ?: null;
        $payment->save();

        /*Ensure payment number generated*/
        if (! $payment->number || strlen($payment->number) == 0) {
            $payment->number = $payment->client->getNextPaymentNumber($payment->client);
        }

        $invoice_totals = 0;
        $credit_totals = 0;
        $invoices = false;

        /*Iterate through invoices and apply payments*/
        if (array_key_exists('invoices', $data) && is_array($data['invoices']) && count($data['invoices']) > 0) {
            $invoice_totals = array_sum(array_column($data['invoices'], 'amount'));
            $refund_totals = array_sum(array_column($data['invoices'], 'refunded'));

            $invoices = Invoice::whereIn('id', array_column($data['invoices'], 'invoice_id'))->withTrashed()->get();

            $payment->invoices()->saveMany($invoices);

            $payment->invoices->each(function ($inv) use ($invoice_totals, $refund_totals) {
                $inv->pivot->amount = $invoice_totals;
                $inv->pivot->refunded = $refund_totals;
                $inv->pivot->save();

                $inv->paid_to_date += $invoice_totals;
                $inv->save();

            });
        }

        if (array_key_exists('credits', $data) && is_array($data['credits']) && count($data['credits']) > 0) {
            $credit_totals = array_sum(array_column($data['credits'], 'amount'));

            $credits = Credit::whereIn('id', array_column($data['credits'], 'credit_id'))->withTrashed()->get();

            $payment->credits()->saveMany($credits);

            $payment->credits->each(function ($cre) use ($credit_totals) {
                $cre->pivot->amount = $credit_totals;
                $cre->pivot->save();

                $cre->paid_to_date += $invoice_totals;
                $cre->save();
            });
        }


        $fields = new stdClass;

        $fields->payment_id = $payment->id;
        $fields->user_id = $payment->user_id;
        $fields->company_id = $payment->company_id;
        $fields->activity_type_id = Activity::CREATE_PAYMENT;

        foreach ($payment->invoices as $invoice) {
            $fields->invoice_id = $invoice->id;

            $this->activity_repo->save($fields, $invoice, Ninja::eventVars());
        }

        if ($invoices && count($invoices) == 0) {
            $this->activity_repo->save($fields, $payment, Ninja::eventVars());
        }

        if ($invoice_totals == $payment->amount) {
            $payment->applied += $payment->amount;
        } elseif ($invoice_totals < $payment->amount) {
            $payment->applied += $invoice_totals;
        }

        $payment->save();

        return $payment->fresh();
    }

    /**
     * If the client is paying in a currency other than
     * the company currency, we need to set a record.
     * @param $data
     * @param $payment
     * @return
     */
    private function processExchangeRates($data, $payment)
    {
        $client = Client::where('id', $data['client_id'])->withTrashed()->first();

        $client_currency = $client->getSetting('currency_id');
        $company_currency = $client->company->settings->currency_id;

        if ($company_currency != $client_currency) {
            $currency = $client->currency();

            $exchange_rate = new CurrencyApi();

            $payment->exchange_rate = $exchange_rate->exchangeRate($client_currency, $company_currency, Carbon::parse($payment->date));
            $payment->exchange_currency_id = $client_currency;
        }

        return $payment;
    }

    public function delete($payment)
    {
        //cannot double delete a payment
        if ($payment->is_deleted) {
            return;
        }

        $payment->service()->deletePayment();

        return parent::delete($payment);
    }

    public function restore($payment)
    {
        //we cannot restore a deleted payment.
        if ($payment->is_deleted) {
            return;
        }

        return parent::restore($payment);
    }
}
