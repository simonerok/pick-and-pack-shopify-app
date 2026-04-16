/**
 * Webshipper API 2.0 – order and shipment types (JSON API).
 * Docs: https://docs.webshipper.io/
 */

/** Order from GET /v2/orders (attributes we use for matching and display) */
export type WebshipperOrder = {
    id: number;
    /** Final reference we use for matching (from first line ext_ref/visible_ref, or order visible_ref, or top-level reference) */
    reference: string | null;
    /** Raw value from first order line's ext_ref (what the API actually returned) */
    first_line_ext_ref: string | null;
    /** Raw value from first order line's visible_ref (what the API actually returned) */
    first_line_visible_ref: string | null;
    /** Raw value from order-level visible_ref attribute (what the API actually returned) */
    order_visible_ref: string | null;
    /** Raw value from order-level reference attribute (often long numeric id – we skip for matching when 12+ digits) */
    order_reference: string | null;
    status: string | null;
    created_at: string | null;
    updated_at: string | null;
    /** Tracking number(s) from linked shipment(s), if any */
    tracking_numbers: string[];
    /** Carrier name(s) from shipment(s), if any */
    carrier_names: string[];
    /** True when the order has at least one linked shipment in Webshipper (required to show Print label) */
    has_shipment: boolean;
    /** First linked shipment id (for linking to shipment in dashboard when present) */
    shipment_id: number | null;
  };