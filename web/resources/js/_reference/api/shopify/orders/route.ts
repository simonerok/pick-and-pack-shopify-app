import { NextResponse } from "next/server";
import type { ShopifyOrder, ShopifyOrderAddress } from "@/app/types/shopify";
import { getAppStatus } from "@/app/lib/app-status";
import {
  isBCConfigured,
  getBCAccessToken,
  resolveBCCompany,
  getBCSalesOrders,
  getBCOrderShipmentDate,
  getBCExpectedReceiptByItem,
} from "@/app/lib/business-central";
import {
  isWebshipperConfigured,
  getWebshipperOrders,
} from "@/app/lib/webshipper";
import type { BCSalesOrder } from "@/app/types/business-central";

const ORDERS_QUERY = `
  query Orders($first: Int!, $query: String, $after: String) {
    orders(first: $first, query: $query, after: $after, sortKey: PROCESSED_AT, reverse: true) {
      edges {
        node {
          id
          name
          number
          email
          createdAt
          updatedAt
          displayFinancialStatus
          displayFulfillmentStatus
          totalPriceSet { shopMoney { amount currencyCode } presentmentMoney { amount currencyCode } }
          subtotalPriceSet { shopMoney { amount currencyCode } presentmentMoney { amount currencyCode } }
          totalTaxSet { shopMoney { amount currencyCode } presentmentMoney { amount currencyCode } }
          cancelledAt
          closedAt
          tags
          note
          sourceName
          billingAddress {
            firstName
            lastName
            address1
            address2
            city
            provinceCode
            countryCodeV2
            zip
          }
          shippingAddress {
            firstName
            lastName
            address1
            address2
            city
            provinceCode
            countryCodeV2
            zip
          }
          lineItems(first: 25) {
            edges {
              node {
                id
                title
                quantity
                originalUnitPriceSet {
                  presentmentMoney { amount currencyCode }
                }
                discountedUnitPriceSet {
                  presentmentMoney { amount currencyCode }
                }
                variant {
                  sku
                  sellableOnlineQuantity
                  selectedOptions {
                    name
                    value
                  }
                  product {
                    id
                  }
                }
                customAttributes {
                  key
                  value
                }
              }
            }
          }
          fulfillmentOrders(first: 3) {
            edges {
              node {
                deliveryMethod {
                  methodType
                }
                lineItems(first: 25) {
                  edges {
                    node {
                      lineItem { id }
                      totalQuantity
                    }
                  }
                }
              }
            }
          }
        }
      }
      pageInfo { hasNextPage endCursor }
    }
  }
`;

type GqlOrder = {
  id: string;
  name: string;
  number: number;
  email: string | null;
  createdAt: string;
  updatedAt: string;
  displayFinancialStatus: string;
  displayFulfillmentStatus: string | null;
  totalPriceSet: {
    shopMoney: { amount: string; currencyCode: string };
    presentmentMoney: { amount: string; currencyCode: string };
  };
  subtotalPriceSet: {
    shopMoney: { amount: string; currencyCode: string };
    presentmentMoney: { amount: string; currencyCode: string };
  } | null;
  totalTaxSet: {
    shopMoney: { amount: string; currencyCode: string };
    presentmentMoney: { amount: string; currencyCode: string };
  } | null;
  cancelledAt: string | null;
  closedAt: string | null;
  tags: string[];
  note: string | null;
  sourceName: string | null;
  billingAddress: {
    firstName: string;
    lastName: string;
    address1?: string;
    address2?: string | null;
    city: string;
    provinceCode: string;
    countryCodeV2: string | null;
    zip?: string;
  } | null;
  shippingAddress: {
    firstName: string;
    lastName: string;
    address1?: string;
    address2?: string | null;
    city: string;
    provinceCode: string;
    countryCodeV2: string | null;
    zip?: string;
  } | null;
  lineItems: {
    edges: Array<{
      node: {
        id: string;
        title: string;
        quantity: number;
        originalUnitPriceSet: {
          presentmentMoney: { amount: string; currencyCode: string };
        } | null;
        discountedUnitPriceSet?: {
          presentmentMoney: { amount: string; currencyCode: string };
        } | null;
        variant: {
          sku?: string | null;
          sellableOnlineQuantity: number;
          selectedOptions?: Array<{ name: string; value: string }>;
          product?: { id?: string } | null;
        } | null;
        customAttributes?: Array<{ key: string; value: string }>;
      };
    }>;
  };
  fulfillmentOrders?: {
    edges: Array<{
      node: {
        deliveryMethod?: { methodType: string } | null;
        lineItems: {
          edges: Array<{
            node: {
              lineItem: { id: string };
              totalQuantity: number;
            };
          }>;
        };
      };
    }>;
  };
}

type GqlResponse = {
  data?: {
    orders: {
      edges: { node: GqlOrder }[];
      pageInfo: { hasNextPage: boolean; endCursor: string | null };
    };
  };
  errors?: Array<{ message: string }>;
};

function parseOrderId(gid: string): number {
  const match = gid.match(/\/Order\/(\d+)$/);
  return match ? parseInt(match[1], 10) : 0;
}

function mapAddress(
  a: GqlOrder["billingAddress"] | GqlOrder["shippingAddress"]
): ShopifyOrderAddress | null {
  if (!a) return null;
  return {
    first_name: a.firstName,
    last_name: a.lastName,
    address1: a.address1 ?? "",
    address2: a.address2 ?? null,
    city: a.city,
    province: a.provinceCode ?? "",
    country: a.countryCodeV2 ?? "",
    zip: a.zip ?? "",
  };
}

function gqlOrderToOrder(node: GqlOrder): ShopifyOrder {
  const totalSet = node.totalPriceSet;
  const subtotalSet = node.subtotalPriceSet;
  const taxSet = node.totalTaxSet;
  const presentmentTotal = totalSet?.presentmentMoney ?? totalSet?.shopMoney;
  const presentmentSubtotal = subtotalSet?.presentmentMoney ?? subtotalSet?.shopMoney;
  const presentmentTax = taxSet?.presentmentMoney ?? taxSet?.shopMoney;
  const currency = presentmentTotal?.currencyCode ?? "USD";

  const committedByLineId = new Map<string, number>();
  for (const foEdge of node.fulfillmentOrders?.edges ?? []) {
    for (const foLiEdge of foEdge.node.lineItems?.edges ?? []) {
      const foLi = foLiEdge.node;
      const lineId = foLi.lineItem?.id;
      if (lineId) {
        committedByLineId.set(
          lineId,
          (committedByLineId.get(lineId) ?? 0) + foLi.totalQuantity
        );
      }
    }
  }

  const line_items = (node.lineItems?.edges ?? []).map(({ node: li }) => {
    const committed_quantity = committedByLineId.get(li.id) ?? 0;
    const inventory_quantity = li.variant?.sellableOnlineQuantity ?? null;
    const custom_item = li.variant == null;
    const properties = (li.customAttributes ?? []).map((attr) => ({
      name: attr.key,
      value: attr.value,
    }));
    const variant_options = (li.variant?.selectedOptions ?? [])
      .filter((opt) => !(opt.name === "Title" && opt.value === "Default Title"))
      .map((opt) => ({ name: opt.name, value: opt.value }));
    const productGid = li.variant?.product?.id ?? null;
    const discounted = li.discountedUnitPriceSet?.presentmentMoney;
    const original = li.originalUnitPriceSet?.presentmentMoney;
    const unitPriceMoney = discounted ?? original;
    return {
      title: li.title,
      quantity: li.quantity,
      unit_price: unitPriceMoney?.amount ?? "0",
      currency: unitPriceMoney?.currencyCode ?? currency,
      product_id: productGid,
      sku: li.variant?.sku ?? null,
      expected_receipt_date: null as string | null,
      inventory_quantity,
      committed_quantity,
      custom_item,
      properties,
      variant_options,
    };
  });
  return {
    id: parseOrderId(node.id),
    name: node.name,
    order_number: node.number,
    email: node.email ?? null,
    created_at: node.createdAt,
    updated_at: node.updatedAt,
    total_price: presentmentTotal?.amount ?? "0",
    subtotal_price: presentmentSubtotal?.amount ?? "0",
    total_tax: presentmentTax?.amount ?? "0",
    currency,
    financial_status: node.displayFinancialStatus?.toLowerCase() ?? "",
    fulfillment_status: node.displayFulfillmentStatus?.toLowerCase() ?? null,
    archived: false,
    cancelled_at: node.cancelledAt,
    closed_at: node.closedAt,
    tags: node.tags ?? [],
    note: node.note ?? null,
    billing_address: mapAddress(node.billingAddress),
    shipping_address: mapAddress(node.shippingAddress),
    delivery_method: (() => {
      const sourceName = (node.sourceName ?? "").toLowerCase();
      if (sourceName === "pos") return "In store purchase";
      const foEdges = node.fulfillmentOrders?.edges ?? [];
      const firstFo = foEdges[0]?.node;
      const methodType = firstFo?.deliveryMethod?.methodType;
      if (methodType === "PICK_UP" || methodType === "PICKUP_POINT") return "Pickup";
      if (methodType === "SHIPPING" || methodType === "LOCAL" || methodType === "RETAIL" || methodType === "NONE") return "Shipping";
      if (methodType) return "Shipping";
      return null;
    })(),
    line_items,
  };
}

function twoMonthsAgoISO(): string {
  const d = new Date();
  d.setMonth(d.getMonth() - 2);
  return d.toISOString().slice(0, 10);
}

export async function GET(request: Request) {
  const store = process.env.SHOPIFY_STORE_DOMAIN;
  const token = process.env.SHOPIFY_ACCESS_TOKEN;

  if (!store || !token) {
    return NextResponse.json(
      {
        error: "Missing Shopify config",
        detail: "Set SHOPIFY_STORE_DOMAIN and SHOPIFY_ACCESS_TOKEN in .env.local",
      },
      { status: 500 }
    );
  }

  let archived = false;
  try {
    const urlObj = new URL(request.url ?? "", "http://localhost");
    archived = urlObj.searchParams.get("archived") === "true";
  } catch {
    // default to non-archived if URL parsing fails
  }
  const orderQuery = archived
    ? `status:closed AND created_at:>=${twoMonthsAgoISO()}`
    : "status:not_closed";

  const url = `https://${store}/admin/api/2025-10/graphql.json`;
  const allOrders: ShopifyOrder[] = [];
  let cursor: string | null = null;
  const first = 50;

  try {
    for (;;) {
      const variables: { first: number; query: string; after?: string } = {
        first,
        query: orderQuery,
      };
      if (cursor) variables.after = cursor;

      const res = await fetch(url, {
        method: "POST",
        headers: {
          "X-Shopify-Access-Token": token,
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ query: ORDERS_QUERY, variables }),
      });

      const json: GqlResponse = await res.json();

      if (!res.ok) {
        return NextResponse.json(
          { error: "Shopify API error", detail: await res.text() },
          { status: res.status }
        );
      }

      if (json.errors?.length) {
        return NextResponse.json(
          { error: "GraphQL error", detail: json.errors.map((e) => e.message).join("; ") },
          { status: 500 }
        );
      }

      const ordersConnection = json.data?.orders;
      if (!ordersConnection) {
        return NextResponse.json(
          { error: "Unexpected response", detail: "No orders in response" },
          { status: 500 }
        );
      }

      const allowedFinancialStatuses = archived
        ? null
        : ["authorized", "paid", "partially_paid"];
      const edges = ordersConnection.edges ?? [];
      for (const { node } of edges) {
        const order = gqlOrderToOrder(node);
        if (archived) {
          (order as ShopifyOrder & { archived: boolean }).archived = true;
        }
        if (!allowedFinancialStatuses || allowedFinancialStatuses.includes(order.financial_status)) {
          allOrders.push(order);
        }
      }

      if (!ordersConnection.pageInfo.hasNextPage) break;
      cursor = ordersConnection.pageInfo.endCursor;
      if (!cursor) break;
    }

    // Optionally enrich with Business Central data (match by External Document No. or BC number)
    if (isBCConfigured()) {
      try {
        const token = await getBCAccessToken();
        const resolved = await resolveBCCompany(token);
        if (!resolved) throw new Error("No company found");

        const bcOrders = await getBCSalesOrders(token, resolved.companyId);
        const bcByRef = new Map<string, BCSalesOrder>();

        /** Normalize refs like "WEBORDER #10496" or "#10496" to "10496" for matching */
        const normalizeRef = (s: string): string =>
          s
            .replace(/^WEBORDER\s+/i, "")
            .replace(/^\s*#\s*/, "")
            .replace(/^\s+|\s+$/g, "")
            .trim();

        for (const bc of bcOrders) {
          const ext = (bc.externalDocumentNumber ?? "").trim();
          if (ext) {
            bcByRef.set(ext, bc);
            const norm = normalizeRef(ext);
            if (norm) bcByRef.set(norm, bc);
          }
          const num = (bc.number ?? "").trim();
          if (num) bcByRef.set(num, bc);
        }
        for (const order of allOrders) {
          const ref = String(order.order_number);
          const refAlt = order.name.replace(/^\s*#?\s*/, "").trim();
          const bc =
            bcByRef.get(ref) ??
            bcByRef.get(refAlt) ??
            bcByRef.get(`#${ref}`) ??
            bcByRef.get(`WEBORDER #${ref}`) ??
            bcByRef.get(`WEBORDER #${refAlt}`);
          if (bc) {
            (order as ShopifyOrder & { business_central?: object }).business_central = {
              order_id: bc.id,
              number: bc.number,
              status: bc.status,
              fully_shipped: bc.fullyShipped,
              requested_delivery_date: bc.requestedDeliveryDate ?? null,
              shipment_date: null,
            };
          }
        }

        // Fetch shipment date from BC order lines (salesOrderLines.shipmentDate) for each matched order
        const withBc = allOrders.filter((o) => (o as ShopifyOrder & { business_central?: object }).business_central);
        const shipmentDates = await Promise.all(
          withBc.map((order) =>
            getBCOrderShipmentDate(
              token,
              resolved.companyId,
              (order as ShopifyOrder & { business_central: { order_id: string } }).business_central.order_id
            )
          )
        );
        withBc.forEach((order, i) => {
          const bc = (order as ShopifyOrder & { business_central: { shipment_date?: string | null } }).business_central;
          if (bc) bc.shipment_date = shipmentDates[i] ?? null;
        });

        // Enrich line items with expected receipt date from BC purchase order lines (for out-of-stock items)
        const expectedReceiptByItem = await getBCExpectedReceiptByItem(token, resolved.companyId);
        for (const order of allOrders) {
          for (const item of order.line_items) {
            const available = item.inventory_quantity ?? 0;
            if (available < item.quantity && item.sku) {
              const date = expectedReceiptByItem.get(item.sku);
              if (date) item.expected_receipt_date = date;
            }
          }
        }
      } catch (bcErr) {
        // Log but do not fail the whole request; orders still return without BC data
        console.error("Business Central fetch failed:", bcErr);
      }
    }

    // Optionally enrich with Webshipper data (match by order reference)
    // Do not use long numeric ids – match only on Shopify order number / name (from order line ext_ref or similar)
    if (isWebshipperConfigured()) {
      try {
        const wsOrders = await getWebshipperOrders({ maxPages: 15, includeShipments: true });
        const wsByRef = new Map<string, (typeof wsOrders)[0]>();

        const normalizeWsRef = (s: string): string =>
          s
            .replace(/^WEBORDER\s+/i, "")
            .replace(/^\s*#+\s*/, "")
            .replace(/^\s+|\s+$/g, "")
            .trim();

        const isLongNumericId = (s: string) => /^\d{12,}$/.test(s);

        for (const ws of wsOrders) {
          const ref = (ws.reference ?? "").trim();
          if (!ref) continue;
          if (isLongNumericId(ref)) continue;
          wsByRef.set(ref, ws);
          const norm = normalizeWsRef(ref);
          if (norm) wsByRef.set(norm, ws);
        }

        for (const order of allOrders) {
          const orderNumber = String(order.order_number);
          const refAlt = order.name.replace(/^\s*#+\s*/, "").trim();
          const ws =
            wsByRef.get(orderNumber) ??
            wsByRef.get(refAlt) ??
            wsByRef.get(`#${orderNumber}`) ??
            wsByRef.get(`##${orderNumber}`) ??
            wsByRef.get(`WEBORDER #${orderNumber}`) ??
            wsByRef.get(`WEBORDER #${refAlt}`);
          if (ws) {
            const account = process.env.WEBSHIPPER_ACCOUNT_NAME?.trim();
            const orderUrl =
              account && /^[a-z0-9_.-]+$/i.test(account)
                ? `https://${account}.webshipper.io/ship/orders/${ws.id}`
                : null;
            const shipmentUrl =
              account && /^[a-z0-9_.-]+$/i.test(account) && ws.shipment_id != null
                ? `https://${account}.webshipper.io/ship/shipments/${ws.shipment_id}`
                : null;
            (order as ShopifyOrder & { webshipper?: object }).webshipper = {
              order_id: ws.id,
              status: ws.status ?? null,
              tracking_numbers: ws.tracking_numbers ?? [],
              carrier_names: ws.carrier_names ?? [],
              order_url: orderUrl,
              shipment_url: shipmentUrl,
              has_shipment: ws.has_shipment ?? false,
            };
          }
        }
      } catch (wsErr) {
        console.error("Webshipper fetch failed:", wsErr);
      }
    }

    const raw = process.env.SHOPIFY_STORE_DOMAIN?.trim() || null;
    const shop_domain = raw
      ? raw.startsWith("http")
        ? new URL(raw).hostname
        : raw
      : null;
    const webshipper_account = process.env.WEBSHIPPER_ACCOUNT_NAME?.trim() || null;
    return NextResponse.json({
      orders: allOrders,
      shop_domain,
      webshipper_account: isWebshipperConfigured() ? webshipper_account : null,
      VITE_APP_STATUS: getAppStatus(),
    });
  } catch (e) {
    const message = e instanceof Error ? e.message : String(e);
    console.error("GET /api/shopify/orders failed:", e);
    return NextResponse.json(
      { error: "Failed to fetch orders", detail: message },
      { status: 500 }
    );
  }
}