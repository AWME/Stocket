<?php namespace AWME\Stocket\Classes;

use Request;

use AWME\Stocket\Classes\Calculator as Calc;

use AWME\Stocket\Models\Product;
use AWME\Stocket\Models\Sale;
use AWME\Stocket\Models\ItemSale;

class Invoice{
    
    function _construct(){
        $this->saleId = "";
    }

	/**
	 * Make Invoice / Item List & Total
	 * @param  [array] $saleId
	 * @return [array]        
	 */
	public function make()
	{
        $this->setQty();
        $this->setSubtotal();
        $this->setTaxes();
        $this->setTotal();

        $invoice['items']       = $this->getItems();
        $invoice['subtotal']    = $this->getSubtotal();

        $invoice['taxes']           = $this->getTaxes();
        $invoice['tax_discount']    = $this->opTaxesDiscount();
        $invoice['total']           = $this->opTotal();

		return $invoice;
	}
	

    /**
     * ------------------------------------------------
     * getItems
     * ------------------------------------------------
     *  - get Item List from ItemSale "relation"
     *  - add attr Relation with Product.
     *  
     * @return array awme_items_sales & awme_products(relation)
     */
    public function getItems(){

        $Items = ItemSale::where('sale_id', $this->saleId)->get();
        foreach ($Items as $item => $attr) {
            $Items[$item]['product'] = Product::find($attr['product_id']);
        }
        return $Items;
    }


    /**
     * ------------------------------------------------
     * Set Qty
     * ------------------------------------------------
     * Set qty & subtotal by Model\ItemSale
     *
     * @return  array $Items itemList
     */
    public function setQty()
	{
        # Obtain Invoice Item List
        $Items = $this->getItems();
        
        /**
         * Si existe Request "qty"
         * Obtiene la lista actual
         * Recorre todos los items
         * Aplica sale_price & Subtotal
         */
        if(Request::input('qty')){

            foreach ($Items as $item => $attr) {
                
                $itemQty = Request::input('qty.'.$attr['id'], 1);
                
                if($itemQty){

                    $ItemSale = ItemSale::find($attr['id']);
                    $ItemSale->qty = $itemQty;

                    $price                  = $attr['product']['price'];
                    $ItemSale->sale_price   = ($ItemSale->sale_price <= 0) ? $attr['product']['price'] : $ItemSale->sale_price;
                    $ItemSale->subtotal     = Calc::multiply($ItemSale->sale_price,$itemQty); #Inserta el multiplo entre precio actual x cantidad.
                    $ItemSale->save();
                }
            }
        }else {

            
            /**
             * Si NO existe Request "qty"
             * Obtiene la lista actual
             * Recorre todos los items
             * Si la cantidad es <= 1 aplica el sale_price & subtotal de Model\Product
             */
            foreach ($Items as $item => $attr) {
            
                if($attr['qty'] <= 1 && !Request::input('qty')){

                    $ItemSale = ItemSale::find($attr['id']);
                    $ItemSale->qty = 1;
                    $price = $attr['product']['price'];
                    $ItemSale->sale_price   = ($ItemSale->sale_price <= 0) ? $attr['product']['price'] : $ItemSale->sale_price;
                    $ItemSale->subtotal     = $ItemSale->sale_price;

                    $ItemSale->save();
                }
            }
        }


        return $Items;
	}

	/**
     * ------------------------------------------------
     * Set Subtotal
     * ------------------------------------------------
     * Aplica el subotal en Model\Sale
     * según la suma de "ItemSale>subtotal"
     *
     * @return  array $Items itemList
     */
	public function setSubtotal()
	{

		/**
         * Recalculate Invoice QTY ItemsSale List
         */
        $Items = ItemSale::where('sale_id', $this->saleId)->get()->toArray();

		$operation = array_column($Items, 'subtotal');

        if(!isset($operation))
        	$operation = [];
        
        $Sale = Sale::find($this->saleId);
        $Sale->subtotal = Calc::suma($operation);
        $Sale->save();

        return $Sale->subtotal;
	}


    public function getSubtotal()
    {
        return Calc::format($this->setSubtotal());
    }
    
    /**
     * ------------------------------------------------
     * Set Taxes
     * ------------------------------------------------
     *  - Inserta el los taxes en json en Model\Sale
     *
     * @return  array $taxes from RquestInput 
     */
    public function setTaxes(){

        $Sale = Sale::find($this->saleId);
        $taxes = Request::input('tax');
        if($taxes){
            $Sale->taxes = json_encode(Request::input('tax'));
            $Sale->save();
            
            return $taxes;
        }
    }

    /**
     * ------------------------------------------------
     * Get Taxes
     * ------------------------------------------------
     * - Obtener los taxes desde el modelo
     * 
     * @return array $taxes from Model\Sale
     */
    public function getTaxes()
    {
        $SaleTaxes = Sale::find($this->saleId)->taxes;
        $taxes = json_decode($SaleTaxes);

        return $taxes;
    }

    public function opTaxesDiscount()
    {
        $tax = $this->getTaxes();

        if(@array_key_exists('discount', $tax)){
            if($tax->discount->type == "$"){
                $total = Calc::resta([$this->getSubtotal()], [$tax->discount->amount]); 
            }else if($tax->discount->type == "%"){
                $total = Calc::resta([$this->getSubtotal()], [Calc::percent($tax->discount->amount,$this->getSubtotal())]); 
            }
        }else $total = 0;

        return Calc::format($this->getSubtotal() - $total);
        
    }


    
	public function opTotal()
	{	
		/**
         * Recalculate :
         * - Taxes
         * - Subtotal
         * - Total
         */
        $total = $this->getSubtotal() - $this->opTaxesDiscount();
        $total = Calc::format($total);

        return $total;
	}

    public function setTotal(){

        $Sale = Sale::find($this->saleId);
        $Sale->total = $this->opTotal();
        $Sale->save();
    }
}