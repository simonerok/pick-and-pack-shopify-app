<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Central definitions for Shopify Admin GraphQL documents used by this app.
 */
class GraphQLHelper
{
  public const ORDERS_QUERY = <<<'GQL'
query Orders($first: Int!, $query: String, $after: String) {
  orders(
    first: $first
    query: $query
    after: $after
    sortKey: PROCESSED_AT
    reverse: true
  ) {
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
        totalPriceSet {
          shopMoney {
            amount
            currencyCode
          }
          presentmentMoney {
            amount
            currencyCode
          }
        }
        subtotalPriceSet {
          shopMoney {
            amount
            currencyCode
          }
          presentmentMoney {
            amount
            currencyCode
          }
        }
        totalTaxSet {
          shopMoney {
            amount
            currencyCode
          }
          presentmentMoney {
            amount
            currencyCode
          }
        }
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
                presentmentMoney {
                  amount
                  currencyCode
                }
              }
              discountedUnitPriceSet {
                presentmentMoney {
                  amount
                  currencyCode
                }
              }
              variant {
                # Old variant fields kept for reference:
                # sku
                # sellableOnlineQuantity
                # selectedOptions { name value }
                # product { id }
                id
                title
                sku
                inventoryQuantity
                sellableOnlineQuantity
                selectedOptions {
                  name
                  value
                }
                product {
                  id
                  title
                  productType
                  metafield(namespace: "seed_jewelry", key: "gia_report") {
                    value
                  }
                }
              }
              customAttributes {
                key
                value
              }
            }
          }
        }
        fulfillmentOrders(first: 10) {
          edges {
            node {
              id
              status
              deliveryMethod {
                methodType
              }
              lineItems(first: 25) {
                edges {
                  node {
                    lineItem {
                      id
                    }
                    totalQuantity
                  }
                }
              }
            }
          }
        }
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}
GQL;

  public const TAGS_ADD_MUTATION = <<<'GQL'
mutation TagsAdd($id: ID!, $tags: [String!]!) {
  tagsAdd(id: $id, tags: $tags) {
    userErrors {
      field
      message
    }
  }
}
GQL;

  public const TAGS_REMOVE_MUTATION = <<<'GQL'
mutation TagsRemove($id: ID!, $tags: [String!]!) {
  tagsRemove(id: $id, tags: $tags) {
    userErrors {
      field
      message
    }
  }
}
GQL;

  public const CREATE_PRODUCT_MUTATION = <<<'GQL'
mutation populateProduct($input: ProductInput!) {
  productCreate(input: $input) {
    product {
      id
    }
  }
}
GQL;

  public const APP_SUBSCRIPTION_QUERY = <<<'GQL'
query appSubscription {
  currentAppInstallation {
    activeSubscriptions {
      name, test
    }
  }
}
GQL;

  public const APP_ONE_TIME_PURCHASES_QUERY = <<<'GQL'
query appPurchases($endCursor: String) {
  currentAppInstallation {
    oneTimePurchases(first: 250, sortKey: CREATED_AT, after: $endCursor) {
      edges {
        node {
          name, test, status
        }
      }
      pageInfo {
        hasNextPage, endCursor
      }
    }
  }
}
GQL;

  public const APP_SUBSCRIPTION_CREATE_MUTATION = <<<'GQL'
mutation createPaymentMutation(
  $name: String!
  $lineItems: [AppSubscriptionLineItemInput!]!
  $returnUrl: URL!
  $test: Boolean
) {
  appSubscriptionCreate(
    name: $name
    lineItems: $lineItems
    returnUrl: $returnUrl
    test: $test
  ) {
    confirmationUrl
    userErrors {
      field, message
    }
  }
}
GQL;

  public const APP_PURCHASE_ONE_TIME_CREATE_MUTATION = <<<'GQL'
mutation createPaymentMutation(
  $name: String!
  $price: MoneyInput!
  $returnUrl: URL!
  $test: Boolean
) {
  appPurchaseOneTimeCreate(
    name: $name
    price: $price
    returnUrl: $returnUrl
    test: $test
  ) {
    confirmationUrl
    userErrors {
      field, message
    }
  }
}
GQL;


  public const READY_ORDER_FOR_PICKUP_MUTATION = <<<'GQL'
mutation FulfillmentOrderLineItemsPreparedForPickup($input: FulfillmentOrderLineItemsPreparedForPickupInput!) {
  fulfillmentOrderLineItemsPreparedForPickup(input: $input) {
    userErrors {
      field
      message
    }
  }
}
GQL;

  public const MARK_ORDER_AS_PICKED_UP_MUTATION = <<<'GQL'
mutation MarkPickupOrderFulfilled($fulfillment: FulfillmentInput!) {
  fulfillmentCreate(fulfillment: $fulfillment) {
    fulfillment {
      id
      status
    }
    userErrors {
      field
    }
  }
}
GQL;
}
