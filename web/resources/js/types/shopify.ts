export type ShopifyOrderAddress = {
    first_name: string;
    last_name: string;
    address1: string;
    address2: string | null;
    city: string;
    province: string;
    country: string;
    zip: string;
  };
  
  export type ShopifyLineItemProperty = {
    name: string;
    value: string;
  };
  
  /** Variant option (e.g. Size: M, Color: Blue) */
  export type ShopifyLineItemVariantOption = {
    name: string;
    value: string;
  };
  
  export type ShopifyLineItem = {
    title: string;
    quantity: number;
    unit_price: string;
    currency: string;
    /** Shopify product GID (e.g. gid://shopify/Product/123) for linking to admin */
    product_id: string | null;
    /** Variant SKU (links to BC item number for expected receipt date lookup) */
    sku: string | null;
    /** Expected receipt date from BC purchase order (YYYY-MM-DD), when out of stock and PO exists */
    expected_receipt_date: string | null;
    /** Sellable quantity in stock (null if variant unavailable or not tracked) */
    inventory_quantity: number | null;
    /** Quantity committed to this order via fulfillment orders (inventory allocated to this order) */
    committed_quantity: number;
    /** True when line item has no variant (e.g. custom item from draft order) */
    custom_item: boolean;
    /** Line item properties (e.g. engraving, gift message) */
    properties: ShopifyLineItemProperty[];
    /** Variant options (e.g. Size, Color) */
    variant_options: ShopifyLineItemVariantOption[];
  };
  
  export type ShopifyOrder = {
    id: number;
    name: string;
    order_number: number;
    email: string | null;
    created_at: string;
    updated_at: string;
    total_price: string;
    subtotal_price: string;
    total_tax: string;
    currency: string;
    financial_status: string;
    fulfillment_status: string | null;
    archived: boolean;
    cancelled_at: string | null;
    closed_at: string | null;
    tags: string[];
    note: string | null;
    billing_address: ShopifyOrderAddress | null;
    shipping_address: ShopifyOrderAddress | null;
    /** Delivery/shipping method title from Shopify (e.g. "Standard", "Express") */
    delivery_method: string | null;
    line_items: ShopifyLineItem[];
    /** Linked Business Central sales order (when BC is configured and a match is found) */
    business_central?: {
      order_id: string;
      number: string;
      status: string;
      fully_shipped: boolean;
      /** Requested delivery date from BC (YYYY-MM-DD or null) */
      requested_delivery_date: string | null;
      /** Shipment date from BC order lines (earliest line shipmentDate), when available */
      shipment_date: string | null;
    } | null;
    /** Linked Webshipper order/shipment (when Webshipper is configured and a match is found) */
    webshipper?: {
      order_id: number;
      status: string | null;
      tracking_numbers: string[];
      carrier_names: string[];
      /** Direct link to the order in Webshipper dashboard */
      order_url: string | null;
      /** Direct link to the (first) shipment in Webshipper dashboard, when available */
      shipment_url: string | null;
      /** True when this order has at least one shipment in Webshipper (Print label only shown when true) */
      has_shipment: boolean;
    } | null;
  }