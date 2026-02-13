package za.co.relatives.app.billing

import android.app.Activity
import android.content.Context
import android.util.Log
import com.android.billingclient.api.AcknowledgePurchaseParams
import com.android.billingclient.api.BillingClient
import com.android.billingclient.api.BillingClientStateListener
import com.android.billingclient.api.BillingFlowParams
import com.android.billingclient.api.BillingResult
import com.android.billingclient.api.ProductDetails
import com.android.billingclient.api.Purchase
import com.android.billingclient.api.PurchasesUpdatedListener
import com.android.billingclient.api.QueryProductDetailsParams
import com.android.billingclient.api.QueryPurchasesParams
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlin.coroutines.resume

/**
 * Manages Google Play Billing for the Relatives subscription plans.
 *
 * Three subscription tiers:
 * - **Starter** (`relatives.starter.monthly`)
 * - **Family**  (`relatives.family.monthly`)
 * - **Big**     (`relatives.big.monthly`)
 *
 * Usage:
 * 1. Create an instance and call [connect].
 * 2. Observe [productDetails] for available products.
 * 3. Call [launchPurchaseFlow] to start a purchase.
 * 4. Observe [purchaseState] for results.
 * 5. Call [destroy] when no longer needed.
 */
class BillingManager(context: Context) {

    companion object {
        private const val TAG = "BillingManager"

        val PRODUCT_IDS = listOf(
            "relatives.starter.monthly",
            "relatives.family.monthly",
            "relatives.big.monthly"
        )
    }

    // ------------------------------------------------------------------ //
    //  State flows
    // ------------------------------------------------------------------ //

    sealed class PurchaseState {
        data object Idle : PurchaseState()
        data object Pending : PurchaseState()
        data class Success(val purchase: Purchase) : PurchaseState()
        data class Error(val code: Int, val message: String) : PurchaseState()
    }

    private val _productDetails = MutableStateFlow<List<ProductDetails>>(emptyList())
    val productDetails: StateFlow<List<ProductDetails>> = _productDetails.asStateFlow()

    private val _purchaseState = MutableStateFlow<PurchaseState>(PurchaseState.Idle)
    val purchaseState: StateFlow<PurchaseState> = _purchaseState.asStateFlow()

    private val _isConnected = MutableStateFlow(false)
    val isConnected: StateFlow<Boolean> = _isConnected.asStateFlow()

    // ------------------------------------------------------------------ //
    //  Billing client
    // ------------------------------------------------------------------ //

    private val purchasesUpdatedListener = PurchasesUpdatedListener { result, purchases ->
        handlePurchasesUpdated(result, purchases)
    }

    private val billingClient: BillingClient = BillingClient.newBuilder(context.applicationContext)
        .setListener(purchasesUpdatedListener)
        .enablePendingPurchases()
        .build()

    // ------------------------------------------------------------------ //
    //  Connection
    // ------------------------------------------------------------------ //

    /**
     * Start the billing client connection. On success, automatically queries
     * product details for all three subscription tiers.
     */
    fun connect() {
        if (billingClient.isReady) {
            _isConnected.value = true
            queryProductDetails()
            return
        }

        billingClient.startConnection(object : BillingClientStateListener {
            override fun onBillingSetupFinished(result: BillingResult) {
                if (result.responseCode == BillingClient.BillingResponseCode.OK) {
                    Log.d(TAG, "Billing client connected")
                    _isConnected.value = true
                    queryProductDetails()
                } else {
                    Log.e(TAG, "Billing setup failed: ${result.debugMessage}")
                    _isConnected.value = false
                }
            }

            override fun onBillingServiceDisconnected() {
                Log.w(TAG, "Billing service disconnected")
                _isConnected.value = false
            }
        })
    }

    /** Release resources. Call from onDestroy. */
    fun destroy() {
        if (billingClient.isReady) {
            billingClient.endConnection()
        }
    }

    // ------------------------------------------------------------------ //
    //  Product details
    // ------------------------------------------------------------------ //

    /** Query Google Play for the details of all subscription products. */
    fun queryProductDetails() {
        val productList = PRODUCT_IDS.map { id ->
            QueryProductDetailsParams.Product.newBuilder()
                .setProductId(id)
                .setProductType(BillingClient.ProductType.SUBS)
                .build()
        }

        val params = QueryProductDetailsParams.newBuilder()
            .setProductList(productList)
            .build()

        billingClient.queryProductDetailsAsync(params) { result, detailsList ->
            if (result.responseCode == BillingClient.BillingResponseCode.OK) {
                _productDetails.value = detailsList
                Log.d(TAG, "Queried ${detailsList.size} product details")
            } else {
                Log.e(TAG, "Product details query failed: ${result.debugMessage}")
            }
        }
    }

    // ------------------------------------------------------------------ //
    //  Purchase flow
    // ------------------------------------------------------------------ //

    /**
     * Launch the Google Play purchase flow for the given [productDetails].
     *
     * @param activity The foreground activity required by the billing API.
     * @param details  The [ProductDetails] for the chosen subscription tier.
     * @param offerToken The offer token from the subscription offer. If null,
     *   the first available offer is used.
     * @return The [BillingResult] from `launchBillingFlow`.
     */
    fun launchPurchaseFlow(
        activity: Activity,
        details: ProductDetails,
        offerToken: String? = null
    ): BillingResult {
        _purchaseState.value = PurchaseState.Pending

        val token = offerToken
            ?: details.subscriptionOfferDetails?.firstOrNull()?.offerToken
            ?: run {
                val msg = "No offer token available for ${details.productId}"
                Log.e(TAG, msg)
                _purchaseState.value = PurchaseState.Error(
                    BillingClient.BillingResponseCode.DEVELOPER_ERROR, msg
                )
                return BillingResult.newBuilder()
                    .setResponseCode(BillingClient.BillingResponseCode.DEVELOPER_ERROR)
                    .setDebugMessage(msg)
                    .build()
            }

        val productDetailsParams = BillingFlowParams.ProductDetailsParams.newBuilder()
            .setProductDetails(details)
            .setOfferToken(token)
            .build()

        val flowParams = BillingFlowParams.newBuilder()
            .setProductDetailsParamsList(listOf(productDetailsParams))
            .build()

        return billingClient.launchBillingFlow(activity, flowParams)
    }

    // ------------------------------------------------------------------ //
    //  Purchase handling
    // ------------------------------------------------------------------ //

    private fun handlePurchasesUpdated(
        result: BillingResult,
        purchases: List<Purchase>?
    ) {
        when (result.responseCode) {
            BillingClient.BillingResponseCode.OK -> {
                purchases?.forEach { purchase ->
                    if (purchase.purchaseState == Purchase.PurchaseState.PURCHASED) {
                        acknowledgePurchase(purchase)
                        _purchaseState.value = PurchaseState.Success(purchase)
                    } else if (purchase.purchaseState == Purchase.PurchaseState.PENDING) {
                        _purchaseState.value = PurchaseState.Pending
                    }
                }
            }
            BillingClient.BillingResponseCode.USER_CANCELED -> {
                Log.d(TAG, "Purchase cancelled by user")
                _purchaseState.value = PurchaseState.Idle
            }
            else -> {
                Log.e(TAG, "Purchase error: ${result.responseCode} ${result.debugMessage}")
                _purchaseState.value = PurchaseState.Error(
                    result.responseCode,
                    result.debugMessage ?: "Unknown billing error"
                )
            }
        }
    }

    /**
     * Acknowledge a purchase so Google does not auto-refund after 3 days.
     */
    private fun acknowledgePurchase(purchase: Purchase) {
        if (purchase.isAcknowledged) return

        val params = AcknowledgePurchaseParams.newBuilder()
            .setPurchaseToken(purchase.purchaseToken)
            .build()

        billingClient.acknowledgePurchase(params) { result ->
            if (result.responseCode == BillingClient.BillingResponseCode.OK) {
                Log.d(TAG, "Purchase acknowledged: ${purchase.orderId}")
            } else {
                Log.e(TAG, "Failed to acknowledge purchase: ${result.debugMessage}")
            }
        }
    }

    // ------------------------------------------------------------------ //
    //  Restore / query existing purchases
    // ------------------------------------------------------------------ //

    /**
     * Query existing subscription purchases for this user. Useful for
     * restoring purchases on a new device or after a reinstall.
     *
     * @return List of active [Purchase] objects, or empty if none found.
     */
    suspend fun queryExistingPurchases(): List<Purchase> =
        suspendCancellableCoroutine { cont ->
            val params = QueryPurchasesParams.newBuilder()
                .setProductType(BillingClient.ProductType.SUBS)
                .build()

            billingClient.queryPurchasesAsync(params) { result, purchases ->
                if (result.responseCode == BillingClient.BillingResponseCode.OK) {
                    Log.d(TAG, "Found ${purchases.size} existing purchase(s)")
                    // Acknowledge any un-acknowledged purchases.
                    purchases.forEach { purchase ->
                        if (purchase.purchaseState == Purchase.PurchaseState.PURCHASED &&
                            !purchase.isAcknowledged
                        ) {
                            acknowledgePurchase(purchase)
                        }
                    }
                    cont.resume(purchases)
                } else {
                    Log.e(TAG, "Query purchases failed: ${result.debugMessage}")
                    cont.resume(emptyList())
                }
            }
        }

    /**
     * Helper to find [ProductDetails] by product ID.
     */
    fun getProductById(productId: String): ProductDetails? {
        return _productDetails.value.find { it.productId == productId }
    }

    /**
     * Format a human-readable price string for the given product's first offer.
     */
    fun getFormattedPrice(details: ProductDetails): String? {
        return details.subscriptionOfferDetails
            ?.firstOrNull()
            ?.pricingPhases
            ?.pricingPhaseList
            ?.firstOrNull()
            ?.formattedPrice
    }
}
