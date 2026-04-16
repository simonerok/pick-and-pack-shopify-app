import { NextResponse } from "next/server";
import {
  isBCConfigured,
  getBCAccessToken,
  resolveBCCompany,
  getBCSalesOrders,
  getBCSalesOrderById,
} from "@/app/lib/business-central";

const MAX_MATCHED_TO_FETCH = 30;

/**
 * GET /api/business-central/test
 * Verifies BC connection using only the company from .env (BC_COMPANY_ID or BC_COMPANY_NAME).
 * Returns which company was used and the sales order count for that company.
 *
 * GET /api/business-central/test?withMatches=true
 * Additionally fetches Shopify orders, finds BC orders that have a Shopify match,
 * and for each matched BC order fetches the full sales order (all properties from BC API)
 * and returns them as matchedOrdersWithFullData.
 */
export async function GET(request: Request) {
  if (!isBCConfigured()) {
    return NextResponse.json(
      {
        ok: false,
        message: "Business Central is not configured",
        hint: "Set BC_TENANT_ID, BC_CLIENT_ID, and BC_CLIENT_SECRET in .env.local",
      },
      { status: 200 }
    );
  }

  try {
    const token = await getBCAccessToken();
    const resolved = await resolveBCCompany(token);

    if (!resolved) {
      return NextResponse.json(
        {
          ok: false,
          message: "No company found in Business Central",
        },
        { status: 500 }
      );
    }

    const salesOrders = await getBCSalesOrders(token, resolved.companyId);

    const out: Record<string, unknown> = {
      ok: true,
      message: "Successfully connected to Business Central",
      companyUsed: {
        id: resolved.company.id,
        name: resolved.company.name,
        displayName: resolved.company.displayName ?? null,
      },
      salesOrdersCount: salesOrders.length,
    };

    const url = new URL(request.url);
    const withMatches = url.searchParams.get("withMatches") === "true";

    if (withMatches) {
      const origin = url.origin;
      const shopifyRes = await fetch(`${origin}/api/shopify/orders`);
      if (!shopifyRes.ok) {
        const err = await shopifyRes.json().catch(() => ({}));
        return NextResponse.json(
          {
            ok: false,
            message: "Failed to fetch Shopify orders for matching",
            error: err.detail ?? err.error ?? shopifyRes.statusText,
          },
          { status: 500 }
        );
      }
      const shopifyData = (await shopifyRes.json()) as { orders?: Array<{ business_central?: { order_id: string } }> };
      const orders = shopifyData.orders ?? [];
      const bcOrderIds = Array.from(
      new Set(orders.map((o) => o.business_central?.order_id).filter((id): id is string => Boolean(id)))
    );
      const toFetch = bcOrderIds.slice(0, MAX_MATCHED_TO_FETCH);

      const matchedOrdersWithFullData: Record<string, unknown>[] = [];
      for (const orderId of toFetch) {
        try {
          const full = await getBCSalesOrderById(token, resolved.companyId, orderId, {
            expand: ["salesOrderLines"],
          });
          matchedOrdersWithFullData.push(full);
        } catch (e) {
          matchedOrdersWithFullData.push({
            id: orderId,
            _fetchError: e instanceof Error ? e.message : String(e),
          });
        }
      }

      out.matchedCount = bcOrderIds.length;
      out.matchedOrdersWithFullData = matchedOrdersWithFullData;
      if (bcOrderIds.length > MAX_MATCHED_TO_FETCH) {
        out._note = `Fetched full data for first ${MAX_MATCHED_TO_FETCH} of ${bcOrderIds.length} matched orders.`;
      }
    }

    return NextResponse.json(out);
  } catch (e) {
    const message = e instanceof Error ? e.message : String(e);
    return NextResponse.json(
      {
        ok: false,
        message: "Business Central connection failed",
        error: message,
      },
      { status: 500 }
    );
  }
}