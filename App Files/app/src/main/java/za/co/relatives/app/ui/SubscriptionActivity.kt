package za.co.relatives.app.ui

import android.os.Bundle
import android.content.Intent
import android.net.Uri
import android.util.Log
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.lifecycle.lifecycleScope
import com.android.billingclient.api.ProductDetails
import com.android.billingclient.api.Purchase
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import za.co.relatives.app.billing.BillingManager
import za.co.relatives.app.network.ApiClient
import za.co.relatives.app.ui.theme.RelativesTheme
import za.co.relatives.app.utils.PreferencesManager

/**
 * Full-screen subscription screen shown when the user's trial has ended.
 *
 * Displays the three Relatives subscription tiers (Starter, Family, Big),
 * integrates with [BillingManager] for Google Play purchases, and verifies
 * each purchase on the backend via [ApiClient].
 */
class SubscriptionActivity : ComponentActivity() {

    companion object {
        private const val TAG = "SubscriptionActivity"
    }

    private lateinit var billingManager: BillingManager
    private lateinit var apiClient: ApiClient
    private val prefs by lazy { PreferencesManager(this) }

    private class BillingAccount(val familyId: Long, val accountId: String, val productIds: Set<String>, val session: String)
    private var account by mutableStateOf<BillingAccount?>(null)
    private var availabilityError by mutableStateOf<String?>(null)
    private var verifying by mutableStateOf(false)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        billingManager = BillingManager(this)
        apiClient = ApiClient(this)

        loadBillingAccount()

        setContent {
            RelativesTheme {
                SubscriptionScreen()
            }
        }

        observePurchases()
    }

    private fun loadBillingAccount() {
        val session = prefs.sessionToken
        availabilityError = null
        if (session.isNullOrBlank()) {
            availabilityError = "Sign in to Relatives to view plans."
            return
        }
        lifecycleScope.launch {
            try {
                val status = apiClient.getSubscriptionStatus()
                val setup = status.get("google_play")?.takeIf { it.isJsonObject }?.asJsonObject
                val id = setup?.get("account_id")?.asString
                val familyId = status.getAsJsonObject("status")?.get("family_id")?.asLong
                val products = setup?.getAsJsonArray("product_ids")?.map { it.asString }.orEmpty().toSet()

                availabilityError = when {
                    prefs.sessionToken != session || familyId == null -> "Sign in again to view plans."
                    status.get("can_manage")?.asBoolean != true -> "The family owner manages this subscription."
                    id == null || products.isEmpty() -> "Google Play plans are temporarily unavailable."
                    else -> null
                }
                if (availabilityError == null && id != null && familyId != null) {
                    account = BillingAccount(familyId, id, products, session)
                    billingManager.connect()
                }
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (error: Exception) {
                availabilityError = "Could not load subscription details. Check your connection and try again."
            }
        }
    }

    override fun onDestroy() {
        billingManager.destroy()
        super.onDestroy()
    }

    // ------------------------------------------------------------------ //
    //  Purchase observation
    // ------------------------------------------------------------------ //

    private fun observePurchases() {
        lifecycleScope.launch {
            billingManager.purchaseState.collect { state ->
                when (state) {
                    is BillingManager.PurchaseState.Success -> {
                        verifyOnBackend(state.purchase)
                    }
                    is BillingManager.PurchaseState.Error -> {
                        Toast.makeText(
                            this@SubscriptionActivity,
                            "Purchase failed: ${state.message}",
                            Toast.LENGTH_LONG
                        ).show()
                    }
                    else -> { /* Idle or Pending -- handled in UI */ }
                }
            }
        }
    }

    /**
     * After a successful Google Play purchase, verify the purchase token with
     * the Relatives backend to activate the subscription for the family.
     */
    private fun verifyOnBackend(purchase: Purchase) {
        val activeAccount = account ?: return
        if (verifying || prefs.sessionToken != activeAccount.session) return
        val productId = purchase.products.singleOrNull()?.takeIf { it in activeAccount.productIds } ?: return
        verifying = true
        lifecycleScope.launch {
            try {
                val result = apiClient.verifyPurchase(activeAccount.familyId, productId, purchase.purchaseToken)
                if (result.get("ok")?.asBoolean != true) error("Verification failed")
                if (result.get("acknowledged")?.asBoolean == true) {
                    Toast.makeText(this@SubscriptionActivity, "Subscription activated.", Toast.LENGTH_LONG).show()
                    return@launch
                }
                billingManager.acknowledgeVerifiedPurchase(purchase) { acknowledged ->
                    if (!isDestroyed) Toast.makeText(
                        this@SubscriptionActivity,
                        if (acknowledged) "Subscription activated." else "Subscription verified. Use Restore Purchases to complete it.",
                        Toast.LENGTH_LONG,
                    ).show()
                }
            } catch (cancelled: CancellationException) {
                throw cancelled
            } catch (error: Exception) {
                Log.w(TAG, "Purchase could not be verified")
                Toast.makeText(this@SubscriptionActivity, "Could not verify purchase. Try Restore Purchases.", Toast.LENGTH_LONG).show()
            } finally {
                verifying = false
                billingManager.clearPurchaseState()
            }
        }
    }

    // ------------------------------------------------------------------ //
    //  Compose UI
    // ------------------------------------------------------------------ //

    @Composable
    private fun SubscriptionScreen() {
        val products by billingManager.productDetails.collectAsState()
        val purchaseState by billingManager.purchaseState.collectAsState()
        val connected by billingManager.isConnected.collectAsState()
        val queryComplete by billingManager.queryComplete.collectAsState()
        val availableProducts = products.filter { it.productId in account?.productIds.orEmpty() }

        var isRestoring by remember { mutableStateOf(false) }

        Scaffold { paddingValues ->
            Surface(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(paddingValues),
                color = MaterialTheme.colorScheme.background
            ) {
                Column(
                    modifier = Modifier
                        .fillMaxSize()
                        .verticalScroll(rememberScrollState())
                        .padding(24.dp),
                    horizontalAlignment = Alignment.CenterHorizontally
                ) {
                    Spacer(modifier = Modifier.height(16.dp))

                    Text(
                        text = "Choose Your Plan",
                        style = MaterialTheme.typography.headlineLarge,
                        fontWeight = FontWeight.Bold,
                        textAlign = TextAlign.Center
                    )

                    Spacer(modifier = Modifier.height(8.dp))

                    Text(
                        text = "Monthly subscriptions renew through Google Play until canceled.",
                        style = MaterialTheme.typography.bodyLarge,
                        textAlign = TextAlign.Center,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )

                    Spacer(modifier = Modifier.height(32.dp))

                    if (availabilityError != null) {
                        Text(availabilityError.orEmpty(), textAlign = TextAlign.Center)
                    } else if (account == null || !queryComplete) {
                        LoadingState()
                    } else if (availableProducts.isEmpty()) {
                        Text("No monthly plans are currently available on Google Play.")
                    } else {
                        val isPurchasing = verifying || purchaseState is BillingManager.PurchaseState.Pending

                        availableProducts.forEach { details ->
                            SubscriptionCard(details, isPurchasing, ::launchPurchase)
                            Spacer(modifier = Modifier.height(16.dp))
                        }
                    }

                    Spacer(modifier = Modifier.height(24.dp))

                    // Restore purchases
                    OutlinedButton(
                        onClick = {
                            isRestoring = true
                            lifecycleScope.launch {
                                try {
                                    val existing = billingManager.queryExistingPurchases()
                                    if (existing.isNotEmpty()) {
                                        val active = existing.firstOrNull {
                                            it.purchaseState == Purchase.PurchaseState.PURCHASED &&
                                                it.products.any { id -> id in account?.productIds.orEmpty() }
                                        }
                                        if (active != null) {
                                            verifyOnBackend(active)
                                        } else {
                                            Toast.makeText(
                                                this@SubscriptionActivity,
                                                "No active subscriptions found",
                                                Toast.LENGTH_SHORT
                                            ).show()
                                        }
                                    } else {
                                        Toast.makeText(
                                            this@SubscriptionActivity,
                                            "No previous purchases found",
                                            Toast.LENGTH_SHORT
                                        ).show()
                                    }
                                } catch (e: Exception) {
                                    Toast.makeText(
                                        this@SubscriptionActivity,
                                        "Could not restore purchases",
                                        Toast.LENGTH_SHORT
                                    ).show()
                                } finally {
                                    isRestoring = false
                                }
                            }
                        },
                        enabled = !isRestoring && !verifying && connected && account != null,
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        if (isRestoring) {
                            CircularProgressIndicator(modifier = Modifier.size(16.dp))
                            Spacer(modifier = Modifier.width(8.dp))
                        }
                        Text("Restore Purchases")
                    }

                    OutlinedButton(
                        onClick = {
                            runCatching {
                                startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("https://play.google.com/store/account/subscriptions")))
                            }.onFailure {
                                Toast.makeText(this@SubscriptionActivity, "Open Google Play to manage your subscriptions.", Toast.LENGTH_LONG).show()
                            }
                        },
                        modifier = Modifier.fillMaxWidth(),
                    ) { Text("Manage subscriptions on Google Play") }
                    OutlinedButton(
                        onClick = {
                            startActivity(Intent(this@SubscriptionActivity, za.co.relatives.app.MainActivity::class.java)
                                .putExtra("action_url", "/legal/privacy.php"))
                        },
                        modifier = Modifier.fillMaxWidth(),
                    ) { Text("Relatives Privacy Policy") }
                    Spacer(modifier = Modifier.height(32.dp))
                }
            }
        }
    }

    @Composable
    private fun LoadingState() {
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .height(200.dp),
            contentAlignment = Alignment.Center
        ) {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                CircularProgressIndicator()
                Spacer(modifier = Modifier.height(16.dp))
                Text(
                    text = "Loading plans...",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
        }
    }

    @Composable
    private fun SubscriptionCard(details: ProductDetails, isPurchasing: Boolean, onSubscribe: (ProductDetails) -> Unit) {
        Card(modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(16.dp)) {
            Column(modifier = Modifier.padding(20.dp)) {
                Text(details.name, style = MaterialTheme.typography.titleLarge)
                val price = billingManager.getFormattedPrice(details)
                Text(
                    text = price?.let { "$it per month" } ?: "Unavailable",
                    style = MaterialTheme.typography.headlineSmall,
                    color = MaterialTheme.colorScheme.primary,
                )
                if (details.description.isNotBlank()) {
                    Text(details.description, style = MaterialTheme.typography.bodyMedium)
                }
                Spacer(modifier = Modifier.height(16.dp))
                Button(
                    onClick = { onSubscribe(details) },
                    enabled = price != null && !isPurchasing,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(if (isPurchasing) "Processing…" else "Subscribe with Google Play")
                }
            }
        }
    }

    private fun launchPurchase(details: ProductDetails) {
        val activeAccount = account ?: return
        if (prefs.sessionToken == activeAccount.session && details.productId in activeAccount.productIds) {
            billingManager.launchPurchaseFlow(this, details, activeAccount.accountId)
        }
    }
}
