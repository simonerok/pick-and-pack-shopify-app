"use client";

import React, { useEffect, useMemo, useState } from "react";
import type { ShopifyOrder, ShopifyOrderAddress } from "@/app/types/shopify";

/** Available = sellable in stock + quantity already committed to this order (not for custom items) */
function availableQuantity(item: ShopifyOrder["line_items"][0]): number {
  if (item.custom_item) return 0;
  return (item.inventory_quantity ?? 0) + (item.committed_quantity ?? 0);
}

function isAllProductsAvailable(order: ShopifyOrder): boolean {
  return (
    order.line_items.length > 0 &&
    order.line_items.every(
      (item) => item.quantity <= availableQuantity(item)
    )
  );
}

function orderHasCustomItem(order: ShopifyOrder): boolean {
  return order.line_items.some((item) => item.custom_item);
}

/** Line items that cannot be fulfilled (insufficient stock or custom item) */
function getMissingLineItems(order: ShopifyOrder): ShopifyOrder["line_items"] {
  return order.line_items.filter(
    (item) => item.quantity > availableQuantity(item)
  );
}

/** Percentage of line items on the order that are available for delivery (0–100). Null if no line items. */
function availabilityPercentage(order: ShopifyOrder): number | null {
  if (order.line_items.length === 0) return null;
  const availableCount = order.line_items.filter(
    (item) => item.quantity <= availableQuantity(item)
  ).length;
  return Math.round((availableCount / order.line_items.length) * 100);
}

type TableTab = "pick-and-pack" | "shipped";

const iconClass = "w-3.5 h-3.5 shrink-0";
const IconChevronRight = ({ className }: { className?: string }) => (
  <svg className={className ?? iconClass} fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" aria-hidden><path d="M9 18l6-6-6-6" /></svg>
);
const IconExternalLink = () => (
  <svg className={iconClass} fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" aria-hidden><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6" /><path d="M15 3h6v6" /><path d="M10 14L21 3" /></svg>
);
const IconPrinter = () => (
  <svg className={iconClass} fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" aria-hidden><path d="M6 9V2h12v7" /><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2" /><path d="M6 14h12v8H6z" /></svg>
);
const IconDocumentArrowUp = () => (
  <svg className={iconClass} fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" aria-hidden><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" /><path d="M14 2v6h6" /><path d="M12 18v-6" /><path d="M9 15l3-3 3 3" /></svg>
);
const IconPlus = () => (
  <svg className={iconClass} fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" aria-hidden><path d="M12 5v14" /><path d="M5 12h14" /></svg>
);
const IconArrowUturnLeft = () => (
  <svg className={iconClass} fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" aria-hidden><path d="M3 10h10a4 4 0 014 4v2" /><path d="M7 6l-4 4 4 4" /></svg>
);
const IconParcel = () => (
  <svg className={iconClass} fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" aria-hidden><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z" /><path d="M3.27 6.96L12 12.01l8.73-5.05" /><path d="M12 22.08V12" /></svg>
);
const IconShoppingBag = () => (
  <svg className={iconClass} fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" aria-hidden><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z" /><path d="M3 6h18" /><path d="M16 10a4 4 0 01-8 0" /></svg>
);

export default function OrdersPage() {
  const [orders, setOrders] = useState<ShopifyOrder[]>([]);
  const [shippedOrders, setShippedOrders] = useState<ShopifyOrder[]>([]);
  const [shopDomain, setShopDomain] = useState<string | null>(null);
  const [webshipperAccount, setWebshipperAccount] = useState<string | null>(null);
  /** "production" = mutation buttons enabled; "test" = BC/Webshipper changes disabled */
  const [appStatus, setAppStatus] = useState<"production" | "test">("test");
  const [loading, setLoading] = useState(true);
  const [shippedLoading, setShippedLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [shippedError, setShippedError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<TableTab>("pick-and-pack");
  const [expandedIds, setExpandedIds] = useState<Set<number>>(new Set());
  const [shippedExpandedIds, setShippedExpandedIds] = useState<Set<number>>(new Set());
  const [labelLoadingWsOrderId, setLabelLoadingWsOrderId] = useState<number | null>(null);
  /** When set, show confirmation dialog to ship order and print label for this Webshipper order id */
  const [printLabelConfirmWsOrderId, setPrintLabelConfirmWsOrderId] = useState<number | null>(null);
  const [returnLabelLoadingWsOrderId, setReturnLabelLoadingWsOrderId] = useState<number | null>(null);
  /** GIA input value per order (order id -> value) */
  const [giaInputByOrderId, setGiaInputByOrderId] = useState<Record<number, string>>({});
  /** When set, show confirmation modal to add GIA as comment line to BC */
  const [giaConfirm, setGiaConfirm] = useState<{
    orderId: number;
    orderName: string;
    bcOrderId: string;
    bcOrderNumber: string;
    giaNumber: string;
  } | null>(null);
  /** Order id for which we are creating the BC line (loading) */
  const [giaLoadingOrderId, setGiaLoadingOrderId] = useState<number | null>(null);

  const toggleExpanded = (id: number) => {
    setExpandedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const toggleShippedExpanded = (id: number) => {
    setShippedExpandedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  useEffect(() => {
    fetch("/api/shopify/orders")
      .then((res) => {
        if (!res.ok) return res.json().then((d) => Promise.reject(d));
        return res.json();
      })
      .then((data) => {
        setOrders(data.orders ?? []);
        setShopDomain(data.shop_domain ?? null);
        setWebshipperAccount(data.webshipper_account ?? null);
        setAppStatus(data.VITE_APP_STATUS === "production" ? "production" : "test");
        setError(null);
      })
      .catch((err) => {
        setError(err?.detail ?? err?.error ?? "Failed to load orders");
        setOrders([]);
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    if (activeTab !== "shipped" || shippedOrders.length > 0 || shippedLoading) return;
    setShippedLoading(true);
    setShippedError(null);
    fetch("/api/shopify/orders?archived=true")
      .then((res) => {
        if (!res.ok) return res.json().then((d) => Promise.reject(d));
        return res.json();
      })
      .then((data) => {
        setShippedOrders(data.orders ?? []);
        setShippedError(null);
      })
      .catch((err) => {
        setShippedError(err?.detail ?? err?.error ?? "Failed to load shipped orders");
        setShippedOrders([]);
      })
      .finally(() => setShippedLoading(false));
  }, [activeTab, shippedOrders.length, shippedLoading]);

  const handlePrintLabel = async (wsOrderId: number) => {
    setPrintLabelConfirmWsOrderId(null);
    setLabelLoadingWsOrderId(wsOrderId);
    try {
      const res = await fetch(`/api/webshipper/label?orderId=${encodeURIComponent(wsOrderId)}`);
      const data = await res.json();
      if (!data.ok || typeof data.pdfBase64 !== "string") {
        alert(data.error ?? "Could not load label");
        return;
      }
      const url = `data:application/pdf;base64,${data.pdfBase64}`;
      window.open(url, "_blank", "noopener,noreferrer");
    } catch (e) {
      alert(e instanceof Error ? e.message : "Failed to load label");
    } finally {
      setLabelLoadingWsOrderId(null);
    }
  };

  const handleCreateReturnLabel = async (wsOrderId: number) => {
    setReturnLabelLoadingWsOrderId(wsOrderId);
    try {
      const res = await fetch(`/api/webshipper/return-label?orderId=${encodeURIComponent(wsOrderId)}`);
      const data = await res.json();
      if (!data.ok || typeof data.pdfBase64 !== "string") {
        alert(data.error ?? "Could not create return label");
        return;
      }
      const url = `data:application/pdf;base64,${data.pdfBase64}`;
      window.open(url, "_blank", "noopener,noreferrer");
    } catch (e) {
      alert(e instanceof Error ? e.message : "Failed to create return label");
    } finally {
      setReturnLabelLoadingWsOrderId(null);
    }
  };

  const handleAddGiaConfirm = () => {
    if (!giaConfirm) return;
    const { orderId, bcOrderId, giaNumber } = giaConfirm;
    setGiaConfirm(null);
    setGiaLoadingOrderId(orderId);
    fetch(`/api/business-central/orders/${encodeURIComponent(bcOrderId)}/lines`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ description: giaNumber }),
    })
      .then((res) => res.json())
      .then((data) => {
        if (data.ok) {
          setGiaInputByOrderId((prev) => {
            const next = { ...prev };
            delete next[orderId];
            return next;
          });
        } else {
          alert(data.error ?? "Failed to add GIA to Business Central");
        }
      })
      .catch((e) => {
        alert(e instanceof Error ? e.message : "Failed to add GIA to Business Central");
      })
      .finally(() => setGiaLoadingOrderId(null));
  };

  const formatDate = (s: string) => {
    try {
      const date = new Date(s);
      if (Number.isNaN(date.getTime())) return s;
      return date.toLocaleString(undefined, {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      });
    } catch {
      return s;
    }
  };

  const formatDateOnly = (s: string) => {
    try {
      const date = new Date(s);
      if (Number.isNaN(date.getTime())) return s;
      return date.toLocaleDateString(undefined, {
        year: "numeric",
        month: "short",
        day: "numeric",
      });
    } catch {
      return s;
    }
  };

  /** BC ship date to show: prefer shipment_date (from order lines), else requested_delivery_date; hide if empty or default (year 1) */
  const getBCShipDate = (bc: NonNullable<ShopifyOrder["business_central"]>) => {
    const raw = bc.shipment_date ?? bc.requested_delivery_date ?? null;
    if (!raw || !raw.trim()) return null;
    const date = new Date(raw);
    if (Number.isNaN(date.getTime()) || date.getFullYear() < 1900) return null;
    return raw;
  };

  const formatMoney = (amount: string, currency: string) =>
    new Intl.NumberFormat(undefined, {
      style: "currency",
      currency: currency || "USD",
    }).format(parseFloat(amount || "0"));

  const twoMonthsAgo = useMemo(() => {
    const d = new Date();
    d.setMonth(d.getMonth() - 2);
    return d.getTime();
  }, []);

  const filteredAndSortedOrders = useMemo(() => {
    const filtered = orders.filter(
      (o) => new Date(o.created_at).getTime() >= twoMonthsAgo
    );
    return [...filtered].sort((a, b) => {
      const aReady = isAllProductsAvailable(a);
      const bReady = isAllProductsAvailable(b);
      if (aReady !== bReady) return aReady ? -1 : 1;
      const shipDateA = a.business_central && getBCShipDate(a.business_central);
      const shipDateB = b.business_central && getBCShipDate(b.business_central);
      const sortKeyA = shipDateA ?? "9999-12-31";
      const sortKeyB = shipDateB ?? "9999-12-31";
      return sortKeyA.localeCompare(sortKeyB);
    });
  }, [orders, twoMonthsAgo]);

  const sortedShippedOrders = useMemo(
    () =>
      [...shippedOrders].sort(
        (a, b) =>
          new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
      ),
    [shippedOrders]
  );

  const formatAddress = (a: ShopifyOrderAddress | null) => {
    if (!a) return "—";
    const lines = [
      [a.first_name, a.last_name].filter(Boolean).join(" "),
      a.address1,
      a.address2,
      [a.zip, a.city].filter(Boolean).join(" "),
      [a.province, a.country].filter(Boolean).join(", "),
    ].filter(Boolean);
    return lines.join("\n") || "—";
  };

  const mutationsEnabled = appStatus === "production";
  const testModeTitle = "Test mode – set VITE_APP_STATUS=Production to enable";

  return (
    <>
    <main className="w-full min-w-0 px-4 py-8 text-sm relative">
      <div className="absolute top-4 right-4" role="status" aria-label={`App status: ${appStatus}`}>
        <span
          className={`inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium ${
            appStatus === "production"
              ? "bg-emerald-100 text-emerald-800"
              : "bg-slate-100 text-slate-600"
          }`}
        >
          {appStatus === "production" ? "Production" : "Test"}
        </span>
      </div>
      <div className="flex justify-center mb-6">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 264.57 18.51" width={240}>
          <path d="M23.77.36c-5.09 0-9.08 3.98-9.08 9.07s3.98 9.08 9.08 9.08 9.08-3.99 9.08-9.08S28.86.36 23.77.36m0 16.53c-4.33 0-7.36-3.08-7.36-7.49s3.03-7.5 7.36-7.5 7.36 3.08 7.36 7.5-3.03 7.49-7.36 7.49M120.56.64h1.67V18.2h-1.67zM71.08.64h1.67V18.2h-1.67zM113.2 9.18l-.26-.12.24-.16c.72-.48 1.94-1.6 1.94-3.65 0-2.76-2.16-4.61-5.37-4.61h-5.09V18.2h6.01c3.16 0 5.37-1.95 5.37-4.74 0-2-.93-3.4-2.84-4.29m-6.71-6.91h3.38c1.58 0 3.42.8 3.42 3.05 0 1.94-1.25 3.05-3.42 3.05h-3.38zm4.29 14.15h-4.3v-6.35h4.08c1.77 0 3.65 1.11 3.65 3.17s-1.76 3.17-3.43 3.17M192.15 9.18l-.26-.12.24-.16c.73-.48 1.94-1.6 1.94-3.65 0-2.76-2.16-4.61-5.37-4.61h-5.09V18.2h6.01c3.16 0 5.37-1.95 5.37-4.74 0-2-.92-3.4-2.83-4.29m-6.72-6.91h3.38c1.58 0 3.42.8 3.42 3.05 0 1.94-1.25 3.05-3.42 3.05h-3.38zm4.3 14.15h-4.3v-6.35h4.07c1.77 0 3.65 1.11 3.65 3.17s-1.76 3.17-3.42 3.17M78.04.64V18.2h11.11v-1.78h-9.28v-6.35h6.51V8.36h-6.51v-6.1h8.62V.64zM157.6.64V18.2h11.11v-1.78h-9.29v-6.35h6.52V8.36h-6.52v-6.1h8.63V.64zM253.45.64V18.2h11.12v-1.79h-9.29v-6.34h6.51V8.36h-6.51v-6.1h8.64V.64zM6.37 8.26c-2.72-.98-4.09-1.79-4.09-3.55 0-1.62 1.38-2.8 3.27-2.8 1.66 0 3.1 1.09 3.42 2.55h1.84C10.48 2.07 8.3.36 5.54.36S.58 2.17.58 4.59c0 3.19 2.33 4.34 4.83 5.11 2.61.81 4.24 2.28 4.24 3.83 0 2-1.55 3.35-3.87 3.35-1.87 0-3.47-1.26-3.94-3.09H0c.29 2.53 2.94 4.71 5.79 4.71 3.61 0 5.56-2.56 5.56-4.98 0-3.24-2.16-4.27-4.98-5.28M128.09.64V18.2h10.66v-1.78h-8.84V.64zM142.85.64V18.2h10.67v-1.78h-8.84V.64zM42.46.64h-5.09V18.2h1.83v-7.81h4.07c2.56 0 4.56-2.19 4.56-4.98S45.63.64 42.46.64m.12 8.04H39.2V2.26h3.38c1.65 0 3.42 1.01 3.42 3.21 0 1.98-1.31 3.21-3.42 3.21M222.14 3.71l2.62 6.64h-5.19zM221.8 0l-7.05 18.2h1.78l2.41-6.23h6.45l2.46 6.23h1.79L222.47 0zM245.88.64v7.72h-9.84V.64h-1.82V18.2h1.82v-8.13h9.84v8.13h1.83V.64zM63.66.64v7.72h-9.83V.64H52V18.2h1.83v-8.13h9.83v8.13h1.83V.64zM207.23 12.3c.62 1.08 1.05 2.18 1.5 3.35.3.79.63 1.63 1.04 2.54h1.89c-.55-1.1-.93-2.12-1.32-3.1l-.02-.05c-.48-1.24-.92-2.41-1.62-3.6a5.5 5.5 0 0 0-1.07-1.33l-.17-.15.2-.11c1.52-.84 2.47-2.53 2.47-4.43 0-2.81-2.21-4.77-5.37-4.77h-5.09v17.56h1.83V10.4h1.84c2.43 0 3.32.97 3.88 1.91m-5.71-10.05h3.38c1.65 0 3.43 1.01 3.43 3.21 0 2.37-1.77 3.21-3.43 3.21h-3.38z" />
        </svg>
      </div>

      <div className="flex justify-center mb-6">
        <p className="text-slate-500 text-xs mb-4">
          {activeTab === "pick-and-pack"
            ? "Orders from the last 2 months, ready to pack first. Unarchived, financial status: authorized, paid, or partially paid."
            : "Archived (closed) orders from the last 2 months in Shopify."}
        </p>
      </div>

      <div className="flex gap-6 mb-4 border-b border-slate-200">
        <button
          type="button"
          onClick={() => setActiveTab("pick-and-pack")}
          className={`pb-2.5 text-sm transition-colors -mb-px border-b-2 ${
            activeTab === "pick-and-pack"
              ? "font-semibold text-slate-800 border-slate-800"
              : "font-normal text-slate-600 hover:text-slate-800 border-transparent"
          }`}
        >
          Pick & Pack
        </button>
        <button
          type="button"
          onClick={() => setActiveTab("shipped")}
          className={`pb-2.5 text-sm transition-colors -mb-px border-b-2 ${
            activeTab === "shipped"
              ? "font-semibold text-slate-800 border-slate-800"
              : "font-normal text-slate-600 hover:text-slate-800 border-transparent"
          }`}
        >
          Shipped
        </button>
      </div>

      <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div className="overflow-x-auto md:overflow-visible">
          <table className="w-full text-left orders-table-responsive">
            <thead>
              <tr className="border-b border-slate-200 bg-slate-50/80">
                <th className="w-10 px-2 py-2 text-slate-500" aria-label="Expand" />
                <th className="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">
                  Order
                </th>
                <th className="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">
                  Customer
                </th>
                {activeTab !== "shipped" && (
                  <th className="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">
                    Ship date
                  </th>
                )}
                <th className="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">
                  Delivery method
                </th>
                <th className="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">
                  Financial
                </th>
                <th className="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">
                  Fulfillment
                </th>
                {activeTab !== "shipped" && (
                  <>
                    <th className="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">
                      Availability
                    </th>
                    <th className="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 w-20">
                      Available %
                    </th>
                  </>
                )}
                <th className="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 md:min-w-[18rem]">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td
                    colSpan={activeTab === "shipped" ? 7 : 10}
                    className="px-4 py-6 text-center text-slate-500 text-xs"
                  >
                    <span className="inline-block w-5 h-5 border-2 border-slate-400 border-t-transparent rounded-full animate-spin align-middle mr-2" />
                    Loading orders…
                  </td>
                </tr>
              ) : error ? (
                <tr>
                  <td
                    colSpan={activeTab === "shipped" ? 7 : 10}
                    className="px-4 py-6 text-center text-amber-700 text-xs"
                  >
                    {error}
                  </td>
                </tr>
              ) : (
                <>
              {activeTab === "pick-and-pack" &&
                (filteredAndSortedOrders.length === 0 ? (
                  <tr>
                    <td
                      colSpan={10}
                      className="px-4 py-6 text-center text-slate-500 text-xs"
                    >
                      No orders in the last 2 months.
                    </td>
                  </tr>
                ) : (
                  filteredAndSortedOrders.map((order) => {
                  const isExpanded = expandedIds.has(order.id);
                  const allProductsAvailable = isAllProductsAvailable(order);
                  const missingLineItems = getMissingLineItems(order);
                  const availablePct = availabilityPercentage(order);
                  return (
                    <React.Fragment key={order.id}>
                      <tr
                        className="border-b border-slate-100 hover:bg-slate-50/50"
                      >
                        <td className="w-10 px-2 py-2 align-middle" data-label="">
                          <button
                            type="button"
                            onClick={() => toggleExpanded(order.id)}
                            className="flex items-center justify-center w-5 h-5 rounded border border-black bg-transparent text-black hover:bg-black hover:text-white transition-colors"
                            aria-expanded={isExpanded}
                            title={isExpanded ? "Collapse" : "Expand"}
                          >
                            <IconChevronRight className={`w-3 h-3 shrink-0 transition-transform ${isExpanded ? "rotate-90" : ""}`} />
                          </button>
                        </td>
                        <td className="px-4 py-2 align-middle text-xs" data-label="Order">
                          <div className="flex flex-col gap-0.5">
                            <div className="text-slate-500 text-[11px]">
                              {formatDate(order.created_at)}
                            </div>
                            <span className="font-medium text-slate-800">
                              {order.name}
                            </span>
                            {order.business_central && (
                              <span className="text-slate-600 font-normal" title={`BC sales order: ${order.business_central.number}`}>
                                BC: {order.business_central.number}
                              </span>
                            )}
                            {order.webshipper && (
                              <span className="text-slate-600 font-normal" title={order.webshipper.tracking_numbers.length > 0 ? `Tracking: ${order.webshipper.tracking_numbers.join(", ")}` : `Webshipper order #${order.webshipper.order_id}`}>
                                WS: {order.webshipper.status ?? `#${order.webshipper.order_id}`}
                                {order.webshipper.tracking_numbers.length > 0 && ` (${order.webshipper.tracking_numbers.length} tracking)`}
                              </span>
                            )}
                          </div>
                        </td>
                        <td className="px-4 py-2 text-xs" data-label="Customer">
                          <div className="text-slate-700">
                            {order.billing_address
                              ? `${order.billing_address.first_name} ${order.billing_address.last_name}`
                              : "—"}
                          </div>
                          {order.email && (
                            <div className="text-slate-500 truncate max-w-[180px]">
                              {order.email}
                            </div>
                          )}
                        </td>
                        <td className="px-4 py-2 text-slate-600 whitespace-nowrap text-xs" data-label="Ship date" title={order.business_central?.shipment_date ? "BC shipment date (from order lines)" : order.business_central?.requested_delivery_date ? "BC requested delivery date" : undefined}>
                          {order.business_central && getBCShipDate(order.business_central)
                            ? formatDateOnly(getBCShipDate(order.business_central)!)
                            : "—"}
                        </td>
                        <td className="px-4 py-2 text-slate-600 text-xs" data-label="Delivery">
                          {order.delivery_method ?? "—"}
                        </td>
                        <td className="px-4 py-2" data-label="Financial">
                          <span
                            className={`inline-flex px-2 py-0.5 rounded text-[11px] font-medium ${
                              order.financial_status === "paid"
                                ? "bg-emerald-100 text-emerald-800"
                                : order.financial_status === "pending"
                                  ? "bg-amber-100 text-amber-800"
                                  : "bg-slate-100 text-slate-600"
                            }`}
                          >
                            {order.financial_status}
                          </span>
                        </td>
                        <td className="px-4 py-2" data-label="Fulfillment">
                          <span
                            className={`inline-flex px-2 py-0.5 rounded text-[11px] font-medium ${
                              order.fulfillment_status === "fulfilled"
                                ? "bg-emerald-100 text-emerald-800"
                                : order.fulfillment_status === "partial"
                                  ? "bg-blue-100 text-blue-800"
                                  : "bg-slate-100 text-slate-600"
                            }`}
                          >
                            {order.fulfillment_status ?? "—"}
                          </span>
                        </td>
                        <td className="px-4 py-2 text-slate-600 text-xs" data-label="Availability">
                          {allProductsAvailable ? (
                            <span className="text-emerald-700">
                              All products available
                            </span>
                          ) : missingLineItems.length > 0 ? (
                            <span
                              className="text-red-700 cursor-help"
                              title={missingLineItems
                                .map((item) =>
                                  item.custom_item
                                    ? `${item.title} × ${item.quantity} (custom item)`
                                    : `${item.title} × ${item.quantity}`
                                )
                                .join("\n")}
                            >
                              {missingLineItems.length} product
                              {missingLineItems.length !== 1 ? "s" : ""} missing
                              {missingLineItems.some((item) => item.custom_item)
                                ? " (custom item on order)"
                                : ""}
                            </span>
                          ) : null}
                        </td>
                        <td className="px-4 py-2 text-slate-600 text-xs tabular-nums" data-label="Available %">
                          {availablePct !== null ? `${availablePct}%` : "—"}
                        </td>
                        <td className="px-4 py-2 align-top md:min-w-[18rem] box-border" data-label="Actions">
                          <div className="grid grid-cols-2 gap-1.5">
                            {shopDomain && (
                              <a
                                href={`https://${shopDomain}/admin/orders/${order.id}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center justify-center gap-1 min-w-0 px-2 py-1.5 rounded-md border border-black bg-transparent text-black text-xs font-medium hover:bg-black hover:text-white transition-colors"
                              >
                                <IconShoppingBag />
                                View in Shopify
                              </a>
                            )}
                            {order.webshipper && (order.webshipper.order_url || (webshipperAccount && order.webshipper.order_id)) && (
                              <>
                                <a
                                  href={order.webshipper.order_url ?? (webshipperAccount ? `https://${webshipperAccount}.webshipper.io/ship/orders/${order.webshipper.order_id}` : "#")}
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  className="inline-flex items-center justify-center gap-1 min-w-0 px-2 py-1.5 rounded-md border border-black bg-transparent text-black text-xs font-medium hover:bg-black hover:text-white transition-colors"
                                  title="Open order in Webshipper"
                                >
                                  <IconParcel />
                                  View in Webshipper
                                </a>
                                <button
                                  type="button"
                                  onClick={() => setPrintLabelConfirmWsOrderId(order.webshipper!.order_id)}
                                  disabled={!mutationsEnabled || labelLoadingWsOrderId === order.webshipper?.order_id}
                                  className="inline-flex items-center justify-center gap-1 min-w-0 px-2 py-1.5 rounded-md border border-black bg-transparent text-black text-xs font-medium hover:bg-black hover:text-white transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
                                  title={mutationsEnabled ? "Ship this order in Webshipper and print the label" : testModeTitle}
                                >
                                  <IconPrinter />
                                  {labelLoadingWsOrderId === order.webshipper?.order_id ? "Loading…" : "Print label"}
                                </button>
                              </>
                            )}
                            <button
                              type="button"
                              disabled={!mutationsEnabled}
                              className="inline-flex items-center justify-center gap-1 min-w-0 px-2 py-1.5 rounded-md border border-black bg-transparent text-black text-xs font-medium hover:bg-black hover:text-white transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
                              title={mutationsEnabled ? "Post order to Business Central (coming soon)" : testModeTitle}
                            >
                              <IconDocumentArrowUp />
                              Post order in BC
                            </button>
                          </div>
                        </td>
                      </tr>
                      {isExpanded && (
                        <tr className="bg-slate-50/80">
                          <td colSpan={10} className="px-4 py-4">
                            <div className="space-y-4 text-xs">
                              <div>
                                <h4 className="font-medium text-slate-700 mb-1.5 text-xs">
                                  Products
                                </h4>
                                {order.line_items.length === 0 ? (
                                  <p className="text-slate-500">No line items</p>
                                ) : (
                                  <div className="rounded-lg border border-slate-200 overflow-hidden product-lines-wrap">
                                    <table className="w-full text-left text-xs">
                                      <thead>
                                        <tr className="border-b border-slate-200 bg-slate-100/80">
                                          <th className="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">
                                            Name
                                          </th>
                                          <th className="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 w-40">
                                            GIA
                                          </th>
                                          <th className="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">
                                            Variant
                                          </th>
                                          <th className="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 text-right tabular-nums">
                                            Committed qty
                                          </th>
                                          <th className="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 text-right tabular-nums">
                                            Available qty
                                          </th>
                                          <th className="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 text-right">
                                            Price
                                          </th>
                                          <th className="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 w-24">
                                            Actions
                                          </th>
                                        </tr>
                                      </thead>
                                      <tbody>
                                        {order.line_items.map((item, i) => {
                                          const isCustom = item.custom_item;
                                          const available = availableQuantity(item);
                                          const canFulfill = !isCustom && item.quantity <= available;
                                          const variantText =
                                            (item.variant_options?.length ?? 0) > 0
                                              ? (item.variant_options ?? [])
                                                  .map((o) => `${o.name}: ${o.value}`)
                                                  .join(", ")
                                              : "—";
                                          return (
                                            <tr
                                              key={i}
                                              className="border-b border-slate-100 last:border-b-0 hover:bg-slate-50/50"
                                            >
                                              <td className="px-3 py-2 text-slate-800">
                                                <span className="flex items-center gap-2">
                                                  {!isCustom && (
                                                    <span
                                                      className={`flex-shrink-0 ${
                                                        canFulfill
                                                          ? "text-emerald-600"
                                                          : "text-red-600"
                                                      }`}
                                                      title={
                                                        canFulfill
                                                          ? "In stock or committed – can fulfill"
                                                          : "Insufficient stock"
                                                      }
                                                      aria-hidden
                                                    >
                                                      {canFulfill ? "✓" : "✗"}
                                                    </span>
                                                  )}
                                                  <span>
                                                    {item.title}
                                                    {!canFulfill && item.expected_receipt_date && (
                                                      <span className="text-slate-500 ml-1" title="Expected receipt">
                                                        (ETA: {formatDateOnly(item.expected_receipt_date)})
                                                      </span>
                                                    )}
                                                    {isCustom && (
                                                      <span className="text-slate-500 ml-1">
                                                        (custom item)
                                                      </span>
                                                    )}
                                                    {(item.properties?.length ?? 0) > 0 && (
                                                      <div className="mt-0.5 text-slate-500 text-[11px] space-y-0.5">
                                                        {(item.properties ?? []).map((prop, pi) => (
                                                          <div key={`prop-${pi}`}>
                                                            <span className="font-medium text-slate-600">{prop.name}:</span>{" "}
                                                            {prop.value}
                                                          </div>
                                                        ))}
                                                      </div>
                                                    )}
                                                  </span>
                                                </span>
                                              </td>
                                              <td className="px-3 py-2 align-top w-40 max-w-[10rem] overflow-hidden">
                                                {i === 0 && order.business_central ? (
                                                  <div className="flex flex-col gap-1.5 min-w-0">
                                                    <input
                                                      type="text"
                                                      value={giaInputByOrderId[order.id] ?? ""}
                                                      onChange={(e) =>
                                                        setGiaInputByOrderId((prev) => ({
                                                          ...prev,
                                                          [order.id]: e.target.value,
                                                        }))
                                                      }
                                                      placeholder="GIA number"
                                                      className="w-full min-w-0 px-2 py-1 text-[11px] border border-black rounded bg-transparent text-black focus:outline-none focus:ring-1 focus:ring-black/30"
                                                      disabled={giaLoadingOrderId === order.id}
                                                    />
                                                    <button
                                                      type="button"
                                                      onClick={() => {
                                                        const bc = order.business_central;
                                                        const gia = (giaInputByOrderId[order.id] ?? "").trim();
                                                        if (!gia) {
                                                          alert("Please enter a GIA number.");
                                                          return;
                                                        }
                                                        if (!bc) return;
                                                        setGiaConfirm({
                                                          orderId: order.id,
                                                          orderName: order.name,
                                                          bcOrderId: bc.order_id,
                                                          bcOrderNumber: bc.number,
                                                          giaNumber: gia,
                                                        });
                                                      }}
                                                      disabled={!mutationsEnabled || giaLoadingOrderId === order.id || !(giaInputByOrderId[order.id] ?? "").trim()}
                                                      className="inline-flex items-center justify-center gap-1 w-full min-w-0 px-2 py-1.5 rounded-md border border-black bg-transparent text-black text-xs font-medium hover:bg-black hover:text-white transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
                                                      title={mutationsEnabled ? undefined : testModeTitle}
                                                    >
                                                      {giaLoadingOrderId === order.id ? (
                                                        <>
                                                          <span className="inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                                          Adding…
                                                        </>
                                                      ) : (
                                                        <>
                                                          <IconPlus />
                                                          Add to BC
                                                        </>
                                                      )}
                                                    </button>
                                                  </div>
                                                ) : null}
                                              </td>
                                              <td className="px-3 py-2 text-slate-600">
                                                {variantText}
                                              </td>
                                              <td className="px-3 py-2 text-slate-600 text-right tabular-nums">
                                                {isCustom ? "—" : (item.committed_quantity ?? 0)}
                                              </td>
                                              <td className="px-3 py-2 text-slate-600 text-right tabular-nums">
                                                {isCustom ? "—" : available}
                                              </td>
                                              <td className="px-3 py-2 text-slate-600 text-right tabular-nums">
                                                {formatMoney(item.unit_price, item.currency)}
                                              </td>
                                              <td className="px-3 py-2">
                                                <div className="flex flex-col gap-1">
                                                  {shopDomain && item.product_id && (
                                                    <a
                                                      href={`https://${shopDomain}/admin/products/${item.product_id.split("/").pop()}`}
                                                      target="_blank"
                                                      rel="noopener noreferrer"
                                                      className="inline-flex items-center justify-center gap-1 min-w-[11rem] px-2 py-1 rounded border border-black bg-transparent text-black text-[11px] font-medium hover:bg-black hover:text-white transition-colors"
                                                      title="Open product in Shopify admin"
                                                    >
                                                      <IconShoppingBag />
                                                      View in Shopify
                                                    </a>
                                                  )}
                                                </div>
                                              </td>
                                            </tr>
                                          );
                                        })}
                                      </tbody>
                                    </table>
                                  </div>
                                )}
                                <h4 className="font-medium text-slate-700 mb-1.5 text-xs mt-4 pt-4 border-t border-slate-200">
                                  Tags & note
                                </h4>
                                <div className="space-y-2">
                                  <p className="text-slate-600">
                                    <span className="text-slate-500">Tags: </span>
                                    {(order.tags ?? []).length > 0
                                      ? (order.tags ?? []).join(", ")
                                      : "—"}
                                  </p>
                                  <p className="text-slate-600">
                                    <span className="text-slate-500">Note: </span>
                                    {order.note?.trim() ? order.note : "—"}
                                  </p>
                                </div>
                                {order.webshipper && (
                                  <>
                                    <h4 className="font-medium text-slate-700 mb-1.5 text-xs mt-4 pt-4 border-t border-slate-200">
                                      Shipping (Webshipper)
                                    </h4>
                                    <div className="space-y-1.5 text-slate-600">
                                      <p>
                                        <span className="text-slate-500">Status: </span>
                                        {order.webshipper.status ?? "—"}
                                      </p>
                                      {order.webshipper.carrier_names.length > 0 && (
                                        <p>
                                          <span className="text-slate-500">Carrier(s): </span>
                                          {order.webshipper.carrier_names.join(", ")}
                                        </p>
                                      )}
                                      {order.webshipper.tracking_numbers.length > 0 && (
                                        <p>
                                          <span className="text-slate-500">Tracking: </span>
                                          {order.webshipper.tracking_numbers.join(", ")}
                                        </p>
                                      )}
                                    </div>
                                  </>
                                )}
                              </div>
                              <div className="flex flex-col sm:flex-row gap-6 sm:gap-8 pt-2 border-t border-slate-200">
                                <div>
                                  <h4 className="font-medium text-slate-700 mb-2">
                                    Billing address
                                  </h4>
                                  <p className="text-slate-600 whitespace-pre-line">
                                    {formatAddress(order.billing_address)}
                                  </p>
                                </div>
                                <div>
                                  <h4 className="font-medium text-slate-700 mb-2">
                                    Shipping address
                                  </h4>
                                  <p className="text-slate-600 whitespace-pre-line">
                                    {formatAddress(order.shipping_address)}
                                  </p>
                                </div>
                              </div>
                            </div>
                          </td>
                        </tr>
                      )}
                    </React.Fragment>
                  );
                })
                ))}

              {activeTab === "shipped" &&
                (shippedLoading ? (
                  <tr>
                    <td
                      colSpan={7}
                      className="px-4 py-6 text-center text-slate-500 text-xs"
                    >
                      <span className="inline-block w-5 h-5 border-2 border-slate-400 border-t-transparent rounded-full animate-spin align-middle mr-2" />
                      Loading shipped orders…
                    </td>
                  </tr>
                ) : shippedError ? (
                  <tr>
                    <td
                      colSpan={7}
                      className="px-4 py-6 text-center text-amber-700 text-xs"
                    >
                      {shippedError}
                    </td>
                  </tr>
                ) : sortedShippedOrders.length === 0 ? (
                  <tr>
                    <td
                      colSpan={7}
                      className="px-4 py-6 text-center text-slate-500 text-xs"
                    >
                      No shipped orders in the last 2 months.
                    </td>
                  </tr>
                ) : (
                  sortedShippedOrders.map((order) => {
                    const isExpanded = shippedExpandedIds.has(order.id);
                    return (
                      <React.Fragment key={order.id}>
                        <tr className="border-b border-slate-100 hover:bg-slate-50/50">
                          <td className="w-10 px-2 py-2 align-middle" data-label="">
                            <button
                              type="button"
                              onClick={() => toggleShippedExpanded(order.id)}
                              className="flex items-center justify-center w-5 h-5 rounded border border-black bg-transparent text-black hover:bg-black hover:text-white transition-colors"
                              aria-expanded={isExpanded}
                              title={isExpanded ? "Collapse" : "Expand"}
                            >
                              <IconChevronRight className={`w-3 h-3 shrink-0 transition-transform ${isExpanded ? "rotate-90" : ""}`} />
                            </button>
                          </td>
                          <td className="px-4 py-2 align-middle text-xs" data-label="Order">
                            <div className="flex flex-col gap-0.5">
                              <div className="text-slate-500 text-[11px]">
                                {formatDate(order.created_at)}
                              </div>
                              <span className="font-medium text-slate-800">
                                {order.name}
                              </span>
                              {order.business_central && (
                                <span className="text-slate-600 font-normal" title={`BC sales order: ${order.business_central.number}`}>
                                  BC: {order.business_central.number}
                                </span>
                              )}
                              {order.webshipper && (
                                <span className="text-slate-600 font-normal" title={order.webshipper.tracking_numbers.length > 0 ? `Tracking: ${order.webshipper.tracking_numbers.join(", ")}` : `Webshipper order #${order.webshipper.order_id}`}>
                                  WS: {order.webshipper.status ?? `#${order.webshipper.order_id}`}
                                  {order.webshipper.tracking_numbers.length > 0 && ` (${order.webshipper.tracking_numbers.length} tracking)`}
                                </span>
                              )}
                            </div>
                          </td>
                          <td className="px-4 py-2 text-xs" data-label="Customer">
                            <div className="text-slate-700">
                              {order.billing_address
                                ? `${order.billing_address.first_name} ${order.billing_address.last_name}`
                                : "—"}
                            </div>
                            {order.email && (
                              <div className="text-slate-500 truncate max-w-[180px]">
                                {order.email}
                              </div>
                            )}
                          </td>
                          <td className="px-4 py-2 text-slate-600 text-xs" data-label="Delivery">
                            {order.delivery_method ?? "—"}
                          </td>
                          <td className="px-4 py-2" data-label="Financial">
                            <span
                              className={`inline-flex px-2 py-0.5 rounded text-[11px] font-medium ${
                                order.financial_status === "paid"
                                  ? "bg-emerald-100 text-emerald-800"
                                  : order.financial_status === "pending"
                                    ? "bg-amber-100 text-amber-800"
                                    : "bg-slate-100 text-slate-600"
                              }`}
                            >
                              {order.financial_status}
                            </span>
                          </td>
                          <td className="px-4 py-2" data-label="Fulfillment">
                            <span
                              className={`inline-flex px-2 py-0.5 rounded text-[11px] font-medium ${
                                order.fulfillment_status === "fulfilled"
                                  ? "bg-emerald-100 text-emerald-800"
                                  : order.fulfillment_status === "partial"
                                    ? "bg-blue-100 text-blue-800"
                                    : "bg-slate-100 text-slate-600"
                              }`}
                            >
                              {order.fulfillment_status ?? "—"}
                            </span>
                          </td>
                          <td className="px-4 py-2 align-top md:min-w-[18rem] box-border" data-label="Actions">
                            <div className="grid grid-cols-2 gap-1.5">
                              {shopDomain && (
                                <a
                                  href={`https://${shopDomain}/admin/orders/${order.id}`}
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  className="inline-flex items-center justify-center gap-1 min-w-0 px-2 py-1.5 rounded-md border border-black bg-transparent text-black text-xs font-medium hover:bg-black hover:text-white transition-colors"
                                >
                                  <IconShoppingBag />
                                  View in Shopify
                                </a>
                              )}
                              {order.webshipper && (order.webshipper.shipment_url ?? order.webshipper.order_url ?? (webshipperAccount && order.webshipper.order_id)) && (
                                <a
                                  href={order.webshipper.shipment_url ?? order.webshipper.order_url ?? (webshipperAccount ? `https://${webshipperAccount}.webshipper.io/ship/orders/${order.webshipper.order_id}` : "#")}
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  className="inline-flex items-center justify-center gap-1 min-w-0 px-2 py-1.5 rounded-md border border-black bg-transparent text-black text-xs font-medium hover:bg-black hover:text-white transition-colors"
                                  title={order.webshipper.shipment_url ? "Open shipment in Webshipper" : "Open order in Webshipper"}
                                >
                                  <IconParcel />
                                  View in Webshipper
                                </a>
                              )}
                              {order.webshipper && (
                                <button
                                  type="button"
                                  onClick={() => handleCreateReturnLabel(order.webshipper!.order_id)}
                                  disabled={!mutationsEnabled || returnLabelLoadingWsOrderId === order.webshipper?.order_id}
                                  className="inline-flex items-center justify-center gap-1 min-w-0 px-2 py-1.5 rounded-md border border-black bg-transparent text-black text-xs font-medium hover:bg-black hover:text-white transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
                                  title={mutationsEnabled ? "Create a return label in Webshipper and open the PDF (can be sent to the customer)" : testModeTitle}
                                >
                                  <IconArrowUturnLeft />
                                  {returnLabelLoadingWsOrderId === order.webshipper?.order_id ? "Creating…" : "Create return label"}
                                </button>
                              )}
                            </div>
                          </td>
                        </tr>
                        {isExpanded && (
                          <tr className="bg-slate-50/80">
                            <td colSpan={7} className="px-4 py-4">
                              <div className="space-y-4 text-xs">
                                <div>
                                  <h4 className="font-medium text-slate-700 mb-1.5 text-xs">
                                    Products
                                  </h4>
                                  {order.line_items.length === 0 ? (
                                    <p className="text-slate-500">No line items</p>
                                  ) : (
                                    <div className="rounded-lg border border-slate-200 overflow-hidden product-lines-wrap">
                                      <table className="w-full text-left text-xs">
                                        <thead>
                                          <tr className="border-b border-slate-200 bg-slate-100/80">
                                            <th className="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">
                                              Name
                                            </th>
                                            <th className="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">
                                              Variant
                                            </th>
                                            <th className="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 text-right tabular-nums">
                                              Committed qty
                                            </th>
                                            <th className="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 text-right tabular-nums">
                                              Available qty
                                            </th>
                                            <th className="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 text-right">
                                              Price
                                            </th>
                                            <th className="px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500 w-24">
                                              Actions
                                            </th>
                                          </tr>
                                        </thead>
                                        <tbody>
                                          {order.line_items.map((item, i) => {
                                            const isCustom = item.custom_item;
                                            const available = availableQuantity(item);
                                            const canFulfill = !isCustom && item.quantity <= available;
                                            const variantText =
                                              (item.variant_options?.length ?? 0) > 0
                                                ? (item.variant_options ?? [])
                                                    .map((o) => `${o.name}: ${o.value}`)
                                                    .join(", ")
                                                : "—";
                                            return (
                                              <tr
                                                key={i}
                                                className="border-b border-slate-100 last:border-b-0 hover:bg-slate-50/50"
                                              >
                                                <td className="px-3 py-2 text-slate-800">
                                                  <span className="flex items-center gap-2">
                                                    {!isCustom && (
                                                      <span
                                                        className={`flex-shrink-0 ${
                                                          canFulfill
                                                            ? "text-emerald-600"
                                                            : "text-red-600"
                                                        }`}
                                                        title={
                                                          canFulfill
                                                            ? "In stock or committed – can fulfill"
                                                            : "Insufficient stock"
                                                        }
                                                        aria-hidden
                                                      >
                                                        {canFulfill ? "✓" : "✗"}
                                                      </span>
                                                    )}
                                                    <span>
                                                      {item.title}
                                                      {!canFulfill && item.expected_receipt_date && (
                                                        <span className="text-slate-500 ml-1" title="Expected receipt">
                                                          (ETA: {formatDateOnly(item.expected_receipt_date)})
                                                        </span>
                                                      )}
                                                      {isCustom && (
                                                        <span className="text-slate-500 ml-1">
                                                          (custom item)
                                                        </span>
                                                      )}
                                                      {(item.properties?.length ?? 0) > 0 && (
                                                        <div className="mt-0.5 text-slate-500 text-[11px] space-y-0.5">
                                                          {(item.properties ?? []).map((prop, pi) => (
                                                            <div key={`prop-${pi}`}>
                                                              <span className="font-medium text-slate-600">{prop.name}:</span>{" "}
                                                              {prop.value}
                                                            </div>
                                                          ))}
                                                        </div>
                                                      )}
                                                    </span>
                                                  </span>
                                                </td>
                                                <td className="px-3 py-2 text-slate-600">
                                                  {variantText}
                                                </td>
                                                <td className="px-3 py-2 text-slate-600 text-right tabular-nums">
                                                  {isCustom ? "—" : (item.committed_quantity ?? 0)}
                                                </td>
                                                <td className="px-3 py-2 text-slate-600 text-right tabular-nums">
                                                  {isCustom ? "—" : available}
                                                </td>
                                                <td className="px-3 py-2 text-slate-600 text-right tabular-nums">
                                                  {formatMoney(item.unit_price, item.currency)}
                                                </td>
                                                <td className="px-3 py-2">
                                                  <div className="flex flex-col gap-1">
                                                    {shopDomain && item.product_id && (
                                                      <a
                                                        href={`https://${shopDomain}/admin/products/${item.product_id.split("/").pop()}`}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="inline-flex items-center justify-center gap-1 min-w-[11rem] px-2 py-1 rounded border border-black bg-transparent text-black text-[11px] font-medium hover:bg-black hover:text-white transition-colors"
                                                        title="Open product in Shopify admin"
                                                      >
                                                        <IconShoppingBag />
                                                        View in Shopify
                                                      </a>
                                                    )}
                                                  </div>
                                                </td>
                                              </tr>
                                            );
                                          })}
                                        </tbody>
                                      </table>
                                    </div>
                                  )}
                                  <h4 className="font-medium text-slate-700 mb-1.5 text-xs mt-4 pt-4 border-t border-slate-200">
                                    Tags & note
                                  </h4>
                                  <div className="space-y-2">
                                    <p className="text-slate-600">
                                      <span className="text-slate-500">Tags: </span>
                                      {(order.tags ?? []).length > 0
                                        ? (order.tags ?? []).join(", ")
                                        : "—"}
                                    </p>
                                    <p className="text-slate-600">
                                      <span className="text-slate-500">Note: </span>
                                      {order.note?.trim() ? order.note : "—"}
                                    </p>
                                  </div>
                                  {order.webshipper && (
                                    <>
                                      <h4 className="font-medium text-slate-700 mb-1.5 text-xs mt-4 pt-4 border-t border-slate-200">
                                        Shipping (Webshipper)
                                      </h4>
                                      <div className="space-y-1.5 text-slate-600">
                                        <p>
                                          <span className="text-slate-500">Status: </span>
                                          {order.webshipper.status ?? "—"}
                                        </p>
                                        {order.webshipper.carrier_names.length > 0 && (
                                          <p>
                                            <span className="text-slate-500">Carrier(s): </span>
                                            {order.webshipper.carrier_names.join(", ")}
                                          </p>
                                        )}
                                        {order.webshipper.tracking_numbers.length > 0 && (
                                          <p>
                                            <span className="text-slate-500">Tracking: </span>
                                            {order.webshipper.tracking_numbers.join(", ")}
                                          </p>
                                        )}
                                      </div>
                                    </>
                                  )}
                                </div>
                                <div className="flex flex-col sm:flex-row gap-6 sm:gap-8 pt-2 border-t border-slate-200">
                                  <div>
                                    <h4 className="font-medium text-slate-700 mb-2">
                                      Billing address
                                    </h4>
                                    <p className="text-slate-600 whitespace-pre-line">
                                      {formatAddress(order.billing_address)}
                                    </p>
                                  </div>
                                  <div>
                                    <h4 className="font-medium text-slate-700 mb-2">
                                      Shipping address
                                    </h4>
                                    <p className="text-slate-600 whitespace-pre-line">
                                      {formatAddress(order.shipping_address)}
                                    </p>
                                  </div>
                                </div>
                              </div>
                            </td>
                          </tr>
                        )}
                      </React.Fragment>
                    );
                  })
                ))}
                </>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </main>

    {/* Confirmation: ship order in Webshipper and print label */}
    {printLabelConfirmWsOrderId != null && (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40" role="dialog" aria-modal="true" aria-labelledby="print-label-confirm-title">
        <div className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-5 text-sm">
          <h2 id="print-label-confirm-title" className="font-semibold text-slate-800 mb-2">Print shipping label</h2>
          <p className="text-slate-600 mb-4">
            Ship this order in Webshipper and open the label PDF in a new tab? If no shipment exists yet, one will be created first.
          </p>
          <div className="flex justify-end gap-2">
            <button
              type="button"
              onClick={() => setPrintLabelConfirmWsOrderId(null)}
              className="inline-flex items-center justify-center min-w-[11rem] px-3 py-1.5 rounded-md border border-slate-300 text-slate-700 hover:bg-slate-50"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={() => printLabelConfirmWsOrderId != null && handlePrintLabel(printLabelConfirmWsOrderId)}
              className="inline-flex items-center justify-center min-w-[11rem] px-3 py-1.5 rounded-md bg-amber-600 text-white hover:bg-amber-700"
            >
              Ship and print label
            </button>
          </div>
        </div>
      </div>
    )}

    {/* Confirmation: add GIA as comment line to Business Central */}
    {giaConfirm != null && (
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40" role="dialog" aria-modal="true" aria-labelledby="gia-confirm-title">
        <div className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-5 text-sm">
          <h2 id="gia-confirm-title" className="font-semibold text-slate-800 mb-2">Add GIA to Business Central</h2>
          <p className="text-slate-600 mb-2">
            This will create a new <strong>Comment</strong> line on the linked Business Central sales order with the following details:
          </p>
          <ul className="text-slate-600 mb-4 list-disc list-inside space-y-1">
            <li>Order: <strong>{giaConfirm.orderName}</strong> (BC #{giaConfirm.bcOrderNumber})</li>
            <li>Line type: Comment</li>
            <li>Description: <strong>{giaConfirm.giaNumber}</strong></li>
          </ul>
          <p className="text-slate-500 text-xs mb-4">
            The new line will appear on the sales order in Business Central. You can edit or delete it there if needed.
          </p>
          <div className="flex justify-end gap-2">
            <button
              type="button"
              onClick={() => setGiaConfirm(null)}
              className="inline-flex items-center justify-center gap-1 min-w-[11rem] px-3 py-1.5 rounded-md border border-black bg-transparent text-black text-sm font-medium hover:bg-black hover:text-white transition-colors"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleAddGiaConfirm}
              disabled={!mutationsEnabled}
              className="inline-flex items-center justify-center gap-1 min-w-[11rem] px-3 py-1.5 rounded-md border border-black bg-transparent text-black text-sm font-medium hover:bg-black hover:text-white transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
              title={mutationsEnabled ? undefined : testModeTitle}
            >
              Confirm and add line
            </button>
          </div>
        </div>
      </div>
    )}
    </>
  );
}