/** Business Central sales order (API v2.0) – subset of fields we use */
export type BCSalesOrder = {
    id: string;
    number: string;
    externalDocumentNumber: string | null;
    orderDate: string | null;
    /** Requested delivery date */
    requestedDeliveryDate: string | null;
    status: string; // e.g. "Draft", "In Review", "Open"
    customerName: string | null;
    totalAmountIncludingTax: number | null;
    fullyShipped: boolean;
    lastModifiedDateTime: string | null;
  };
  
  /** Company from BC API (for listing) */
  export type BCCompany = {
    id: string;
    name: string;
    displayName?: string;
  };