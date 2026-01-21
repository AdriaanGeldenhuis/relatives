package za.co.relatives.app.billing

import android.app.Activity
import android.content.Context
import android.util.Log
import com.android.billingclient.api.*
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow

class BillingManager(private val context: Context) {

    private val _subscriptions = MutableStateFlow<List<ProductDetails>>(emptyList())
    val subscriptions = _subscriptions.asStateFlow()

    var onPurchaseCompleted: ((String, String) -> Unit)? = null

    private val purchasesUpdatedListener = PurchasesUpdatedListener { billingResult, purchases ->
        if (billingResult.responseCode == BillingClient.BillingResponseCode.OK && purchases != null) {
            for (purchase in purchases) {
                if (purchase.purchaseState == Purchase.PurchaseState.PURCHASED && !purchase.isAcknowledged) {
                    val productId = purchase.products.firstOrNull()
                    val purchaseToken = purchase.purchaseToken
                    if (productId != null) {
                        onPurchaseCompleted?.invoke(productId, purchaseToken)
                    }
                }
            }
        } else {
            Log.e("BillingManager", "Purchase error: ${billingResult.debugMessage}")
        }
    }

    private var billingClient: BillingClient = BillingClient.newBuilder(context)
        .setListener(purchasesUpdatedListener)
        .enablePendingPurchases()
        .build()

    fun startConnection(onReady: () -> Unit) {
        billingClient.startConnection(object : BillingClientStateListener {
            override fun onBillingSetupFinished(billingResult: BillingResult) {
                if (billingResult.responseCode == BillingClient.BillingResponseCode.OK) {
                    Log.d("BillingManager", "Billing client connected")
                    querySubscriptionProducts()
                    onReady()
                } else {
                    Log.e("BillingManager", "Billing setup failed: ${billingResult.debugMessage}")
                }
            }

            override fun onBillingServiceDisconnected() {
                Log.w("BillingManager", "Billing service disconnected")
                // Implement retry logic here if needed
            }
        })
    }
    
    fun endConnection() {
        if (billingClient.isReady) {
            billingClient.endConnection()
        }
    }

    private fun querySubscriptionProducts() {
        val productList = listOf(
            QueryProductDetailsParams.Product.newBuilder()
                .setProductId("relatives.small.monthly")
                .setProductType(BillingClient.ProductType.SUBS)
                .build(),
            QueryProductDetailsParams.Product.newBuilder()
                .setProductId("relatives.big.monthly")
                .setProductType(BillingClient.ProductType.SUBS)
                .build()
        )

        val params = QueryProductDetailsParams.newBuilder()
            .setProductList(productList)
            .build()

        billingClient.queryProductDetailsAsync(params) { billingResult, productDetailsList ->
            if (billingResult.responseCode == BillingClient.BillingResponseCode.OK) {
                _subscriptions.value = productDetailsList ?: emptyList()
                Log.d("BillingManager", "Found ${productDetailsList?.size ?: 0} products")
            } else {
                Log.e("BillingManager", "Product query failed: ${billingResult.debugMessage}")
            }
        }
    }

    fun launchPurchase(activity: Activity, productDetails: ProductDetails) {
        val offerToken = productDetails.subscriptionOfferDetails?.firstOrNull()?.offerToken ?: return

        val productDetailsParamsList = listOf(
            BillingFlowParams.ProductDetailsParams.newBuilder()
                .setProductDetails(productDetails)
                .setOfferToken(offerToken)
                .build()
        )

        val billingFlowParams = BillingFlowParams.newBuilder()
            .setProductDetailsParamsList(productDetailsParamsList)
            .build()

        billingClient.launchBillingFlow(activity, billingFlowParams)
    }

    fun acknowledgePurchase(purchaseToken: String, onDone: (Boolean) -> Unit) {
        val acknowledgePurchaseParams = AcknowledgePurchaseParams.newBuilder()
            .setPurchaseToken(purchaseToken)
            .build()
            
        billingClient.acknowledgePurchase(acknowledgePurchaseParams) { billingResult ->
            val success = billingResult.responseCode == BillingClient.BillingResponseCode.OK
            if (success) {
                Log.d("BillingManager", "Purchase acknowledged successfully")
            } else {
                Log.e("BillingManager", "Acknowledgement failed: ${billingResult.debugMessage}")
            }
            onDone(success)
        }
    }
}
