<?php

namespace Tests\Unit;

use App\Factory\InvoiceFactory;
use App\Factory\InvoiceItemFactory;
use App\Helpers\Invoice\InvoiceCalc;
use Tests\TestCase;

/**
 * @test
 * @covers  App\Helpers\Invoice\InvoiceCalc
 */
class InvoiceTest extends TestCase
{

	protected $invoice;

	protected $invoice_calc;

	private $settings;

    public function setUp()
    {
    
    parent::setUp();
	
		$this->invoice = InvoiceFactory::create();
		$this->invoice->line_items = $this->buildLineItems();

		$this->settings = $this->buildSettings();

		$this->invoice_calc = new InvoiceCalc($this->invoice, $this->settings);
	}


	private function buildSettings()
	{
		$settings = new \stdClass;
		$settings->custom_taxes1 = true;
		$settings->custom_taxes2 = true;
		$settings->inclusive_taxes = true;
		$settings->precision = 2;

		return $settings;
	}

	private function buildLineItems()
	{
		$line_items = [];

		$item = InvoiceItemFactory::create();
		$item->qty = 1;
		$item->cost =10;

		$line_items[] = $item;

		$item = InvoiceItemFactory::create();
		$item->qty = 1;
		$item->cost =10;

		$line_items[] = $item;

		return $line_items;

	}

	public function testInvoiceTotals()
	{
		$this->invoice_calc->build();

		$this->assertEquals($this->invoice_calc->getSubTotal(), 20);
		$this->assertEquals($this->invoice_calc->getTotal(), 20);
	}

	public function testInvoiceTotalsWithDiscount()
	{
		$this->invoice->discount = 5;
			
		$this->invoice_calc->build();

		$this->assertEquals($this->invoice_calc->getSubTotal(), 20);
		$this->assertEquals($this->invoice_calc->getTotal(), 15);
		$this->assertEquals($this->invoice_calc->getBalance(), 15);
	}

	public function testInvoiceTotalsWithDiscountWithSurcharge()
	{
		$this->invoice->discount = 5;
		$this->invoice->custom_value1 = 5;
			
		$this->invoice_calc->build();

		$this->assertEquals($this->invoice_calc->getSubTotal(), 20);
		$this->assertEquals($this->invoice_calc->getTotal(), 20);
		$this->assertEquals($this->invoice_calc->getBalance(), 20);
	}

	public function testInvoiceTotalsWithDiscountWithSurchargeWithInclusiveTax()
	{
		$this->invoice->discount = 5;
		$this->invoice->custom_value1 = 5;
		$this->invoice->tax_name1 = 'GST';
		$this->invoice->tax_rate1 = 10;


		$this->invoice_calc->build();

		$this->assertEquals($this->invoice_calc->getSubTotal(), 20);
		$this->assertEquals($this->invoice_calc->getTotal(), 20);
		$this->assertEquals($this->invoice_calc->getBalance(), 20);
	}

	public function testInvoiceTotalsWithDiscountWithSurchargeWithExclusiveTax()
	{

		$this->invoice_calc = new InvoiceCalc($this->invoice, $this->settings);

		$this->invoice->discount = 5;
		$this->invoice->custom_value1 = 5;
		$this->invoice->tax_name1 = 'GST';
		$this->invoice->tax_rate1 = 10;
		$this->settings->inclusive_taxes = false;

		$this->invoice_calc->build();

		$this->assertEquals($this->invoice_calc->getSubTotal(), 20);
		$this->assertEquals($this->invoice_calc->getTotal(), 22);
		$this->assertEquals($this->invoice_calc->getBalance(), 22);
		$this->assertEquals($this->invoice_calc->getTotalTaxes(), 2);
	}

		public function testInvoiceTotalsWithDiscountWithSurchargeWithDoubleExclusiveTax()
	{

		$this->invoice_calc = new InvoiceCalc($this->invoice, $this->settings);

		$this->invoice->discount = 5;
		$this->invoice->custom_value1 = 5;
		$this->invoice->tax_name1 = 'GST';
		$this->invoice->tax_rate1 = 10;
		$this->invoice->tax_name2 = 'GST';
		$this->invoice->tax_rate2 = 10;
		$this->settings->inclusive_taxes = false;

		$this->invoice_calc->build();

		$this->assertEquals($this->invoice_calc->getSubTotal(), 20);
		$this->assertEquals($this->invoice_calc->getTotal(), 24);
		$this->assertEquals($this->invoice_calc->getBalance(), 24);
		$this->assertEquals($this->invoice_calc->getTotalTaxes(), 4);
	}




}