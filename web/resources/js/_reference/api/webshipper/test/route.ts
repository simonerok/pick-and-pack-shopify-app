import { NextResponse } from "next/server";
import {
  isWebshipperConfigured,
  getWebshipperOrders,
  getWebshipperOrdersRawFirstPage,
} from "@/app/lib/webshipper";

const MAX_ORDERS_SAMPLE = 20;
const SHOPIFY_SAMPLE = 15;

/**
 * GET /api/webshipper/test
 * Verifies Webshipper connection and returns order count plus a sample of order
 * ids and references (so you can confirm references match your Shopify order numbers).
 *
 * GET /api/webshipper/test?raw=true
 * Also returns raw Webshipper API response (first order's attributes) for debugging.
 */
export async function GET(request: Request) {
  if (!isWebshipperConfigured()) {
    return NextResponse.json(
      {
        ok: false,
        message: "Webshipper is not configured",
        hint: "Set WEBSHIPPER_ACCOUNT_NAME and WEBSHIPPER_ACCESS_TOKEN in .env",
      },
      { status: 200 }
    );
  }

  const url = new URL(request.url);
  const includeRaw = url.searchParams.get("raw") === "true";

  try {
    const account = process.env.WEBSHIPPER_ACCOUNT_NAME?.trim() ?? "";
    const orders = await getWebshipperOrders({
      maxPages: 3,
      includeShipments: true,
    });

    const isLongNumericId = (s: string | null) => (s ? /^\d{12,}$/.test(s.trim()) : false);
    const matchableOrders = orders.filter((o) => o.reference && !isLongNumericId(o.reference));
    const matchableCount = matchableOrders.length;

    const sample = orders.slice(0, MAX_ORDERS_SAMPLE).map((o) => ({
      id: o.id,
      status: o.status,
      // Raw values from Webshipper API – what we actually received
      first_line_ext_ref: o.first_line_ext_ref,
      first_line_visible_ref: o.first_line_visible_ref,
      order_visible_ref: o.order_visible_ref,
      order_reference: o.order_reference,
      // What we use for matching (from line ext_ref/visible_ref, else order visible_ref, else order reference if not long id)
      reference_used_for_matching: o.reference,
      matchable: o.reference ? !isLongNumericId(o.reference) : false,
      tracking_count: o.tracking_numbers.length,
      carrier_names: o.carrier_names,
    }));

    // Fetch Shopify orders so we can show exactly which keys we look up (for comparison)
    let shopifyLookupKeys: { order_number: number; name: string; id: number; lookupKeys: string[] }[] = [];
    try {
      const origin = url.origin || `http://${request.headers.get("host") || "localhost:3000"}`;
      const shopifyRes = await fetch(`${origin}/api/shopify/orders`);
      if (shopifyRes.ok) {
        const data = (await shopifyRes.json()) as { orders?: Array<{ id: number; order_number: number; name: string }> };
        const shopifyOrders = data.orders ?? [];
        shopifyLookupKeys = shopifyOrders.slice(0, SHOPIFY_SAMPLE).map((o) => {
          const num = String(o.order_number);
          const nameNorm = o.name.replace(/^\s*#+\s*/, "").trim();
          return {
            order_number: o.order_number,
            name: o.name,
            id: o.id,
            lookupKeys: [num, nameNorm, `#${num}`, `##${num}`, `WEBORDER #${num}`, `WEBORDER #${nameNorm}`],
          };
        });
      }
    } catch {
      // ignore – Shopify may not be configured or request may fail
    }

    const matchableReferences = Array.from(new Set(matchableOrders.map((o) => o.reference).filter(Boolean))) as string[];

    const out: Record<string, unknown> = {
      ok: true,
      message: "Successfully connected to Webshipper",
      accountUsed: account || "(not set)",
      ordersCount: orders.length,
      matchableByReferenceCount: matchableCount,
      matchableReferencesSample: matchableReferences.slice(0, 30),
      sampleOrders: sample,
      shopifyLookupKeysSample: shopifyLookupKeys,
      matching: {
        explanation:
          "We match when a Webshipper order's 'reference' equals one of the Shopify 'lookupKeys'. Reference is built from: first order line ext_ref or visible_ref, else top-level visible_ref, else top-level reference (if not 12+ digits we skip it).",
        whyNoMatch:
          matchableCount === 0
            ? "No Webshipper orders have a matchable reference (all are long numeric ids or missing). Check raw response (?raw=true) to see which attributes Webshipper returns and configure your integration to store Shopify order number in ext_ref or visible_ref."
            : shopifyLookupKeys.length === 0
              ? "Shopify orders could not be loaded for comparison. Check that Shopify is configured."
              : "If counts look fine but the table still has no Webshipper link, ensure at least one Webshipper reference exactly matches one of the Shopify lookupKeys (e.g. order_number as string).",
      },
    };

    if (includeRaw) {
      const raw = await getWebshipperOrdersRawFirstPage();
      out.raw = raw.ok
        ? {
            firstOrderAttributes: raw.firstOrderAttributes,
            firstOrderRelationships: raw.firstOrderRelationships,
            attributeKeys: raw.firstOrderAttributes ? Object.keys(raw.firstOrderAttributes) : [],
          }
        : { error: raw.error };
    }

    return NextResponse.json(out);
  } catch (e) {
    const message = e instanceof Error ? e.message : String(e);
    return NextResponse.json(
      {
        ok: false,
        message: "Webshipper connection failed",
        error: message,
      },
      { status: 500 }
    );
  }
}