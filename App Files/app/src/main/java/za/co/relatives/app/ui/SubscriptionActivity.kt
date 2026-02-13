package za.co.relatives.app.ui

import android.os.Bundle
import android.util.Log
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
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
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.lifecycleScope
import com.android.billingclient.api.ProductDetails
import com.android.billingclient.api.Purchase
import kotlinx.coroutines.launch
import za.co.relatives.app.RelativesApplication
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
    private lateinit var prefs: PreferencesManager

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = (application as RelativesApplication).preferencesManager
        billingManager = BillingManager(this)
        apiClient = ApiClient(this)

        billingManager.connect()

        setContent {
            RelativesTheme {
                SubscriptionScreen()
            }
        }

        observePurchases()
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
        lifecycleScope.launch {
            try {
                val familyId = prefs.familyId ?: ""
                val productId = purchase.products.firstOrNull() ?: return@launch
                val planCode = productIdToPlanCode(productId)

                val result = apiClient.verifyPurchase(
                    familyId = familyId,
                    planCode = planCode,
                    purchaseToken = purchase.purchaseToken
                )

                val success = result.has("success") && result.get("success").asBoolean
                if (success) {
                    Log.i(TAG, "Purchase verified on backend")
                    Toast.makeText(
                        this@SubscriptionActivity,
                        "Subscription activated!",
                        Toast.LENGTH_SHORT
                    ).show()
                    finish()
                } else {
                    val msg = result.get("message")?.asString ?: "Verification failed"
                    Log.e(TAG, "Backend verification failed: $msg")
                    Toast.makeText(this@SubscriptionActivity, msg, Toast.LENGTH_LONG).show()
                }
            } catch (e: Exception) {
                Log.e(TAG, "Backend verification error", e)
                Toast.makeText(
                    this@SubscriptionActivity,
                    "Could not verify purchase. Please try again.",
                    Toast.LENGTH_LONG
                ).show()
            }
        }
    }

    private fun productIdToPlanCode(productId: String): String = when (productId) {
        "relatives.starter.monthly" -> "starter"
        "relatives.family.monthly" -> "family"
        "relatives.big.monthly" -> "big"
        else -> productId
    }

    // ------------------------------------------------------------------ //
    //  Compose UI
    // ------------------------------------------------------------------ //

    @Composable
    private fun SubscriptionScreen() {
        val products by billingManager.productDetails.collectAsState()
        val purchaseState by billingManager.purchaseState.collectAsState()
        val connected by billingManager.isConnected.collectAsState()

        var isRestoring by remember { mutableStateOf(false) }

        // Attempt to restore purchases on first load.
        LaunchedEffect(connected) {
            if (connected) {
                billingManager.queryProductDetails()
            }
        }

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
                        text = "Your free trial has ended. Subscribe to continue keeping your family connected.",
                        style = MaterialTheme.typography.bodyLarge,
                        textAlign = TextAlign.Center,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )

                    Spacer(modifier = Modifier.height(32.dp))

                    if (!connected || products.isEmpty()) {
                        LoadingState()
                    } else {
                        val isPurchasing = purchaseState is BillingManager.PurchaseState.Pending

                        // Starter tier
                        SubscriptionCard(
                            title = "Starter",
                            description = "For small families. Track up to 3 members.",
                            features = listOf(
                                "Up to 3 family members",
                                "Real-time location sharing",
                                "Push notifications"
                            ),
                            details = products.find { it.productId == "relatives.starter.monthly" },
                            isHighlighted = false,
                            isPurchasing = isPurchasing,
                            onSubscribe = { details -> launchPurchase(details) }
                        )

                        Spacer(modifier = Modifier.height(16.dp))

                        // Family tier (highlighted)
                        SubscriptionCard(
                            title = "Family",
                            description = "Most popular. Perfect for the whole family.",
                            features = listOf(
                                "Up to 6 family members",
                                "Real-time location sharing",
                                "Push notifications",
                                "Shopping lists & calendar"
                            ),
                            details = products.find { it.productId == "relatives.family.monthly" },
                            isHighlighted = true,
                            isPurchasing = isPurchasing,
                            onSubscribe = { details -> launchPurchase(details) }
                        )

                        Spacer(modifier = Modifier.height(16.dp))

                        // Big tier
                        SubscriptionCard(
                            title = "Big Family",
                            description = "For larger households and extended family.",
                            features = listOf(
                                "Up to 12 family members",
                                "Real-time location sharing",
                                "Push notifications",
                                "Shopping lists & calendar",
                                "Priority support"
                            ),
                            details = products.find { it.productId == "relatives.big.monthly" },
                            isHighlighted = false,
                            isPurchasing = isPurchasing,
                            onSubscribe = { details -> launchPurchase(details) }
                        )
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
                                            it.purchaseState == Purchase.PurchaseState.PURCHASED
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
                        enabled = !isRestoring && connected,
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        if (isRestoring) {
                            CircularProgressIndicator(modifier = Modifier.size(16.dp))
                            Spacer(modifier = Modifier.width(8.dp))
                        }
                        Text("Restore Purchases")
                    }

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
    private fun SubscriptionCard(
        title: String,
        description: String,
        features: List<String>,
        details: ProductDetails?,
        isHighlighted: Boolean,
        isPurchasing: Boolean,
        onSubscribe: (ProductDetails) -> Unit
    ) {
        val borderColor = if (isHighlighted) {
            MaterialTheme.colorScheme.primary
        } else {
            MaterialTheme.colorScheme.outlineVariant
        }

        val containerColor = if (isHighlighted) {
            MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.3f)
        } else {
            MaterialTheme.colorScheme.surface
        }

        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(16.dp),
            colors = CardDefaults.cardColors(containerColor = containerColor),
            border = BorderStroke(
                width = if (isHighlighted) 2.dp else 1.dp,
                color = borderColor
            ),
            elevation = CardDefaults.cardElevation(
                defaultElevation = if (isHighlighted) 4.dp else 1.dp
            )
        ) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(20.dp)
            ) {
                if (isHighlighted) {
                    Surface(
                        shape = RoundedCornerShape(4.dp),
                        color = MaterialTheme.colorScheme.primary,
                        modifier = Modifier.padding(bottom = 8.dp)
                    ) {
                        Text(
                            text = "MOST POPULAR",
                            color = MaterialTheme.colorScheme.onPrimary,
                            style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.Bold,
                            modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp)
                        )
                    }
                }

                Text(
                    text = title,
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold
                )

                Spacer(modifier = Modifier.height(4.dp))

                // Price
                val price = details?.let { billingManager.getFormattedPrice(it) }
                Text(
                    text = if (price != null) "$price/month" else "Loading...",
                    style = MaterialTheme.typography.headlineSmall,
                    fontWeight = FontWeight.SemiBold,
                    color = MaterialTheme.colorScheme.primary
                )

                Spacer(modifier = Modifier.height(8.dp))

                Text(
                    text = description,
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )

                Spacer(modifier = Modifier.height(12.dp))

                // Features list
                features.forEach { feature ->
                    Row(
                        modifier = Modifier.padding(vertical = 2.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Text(
                            text = "\u2713",
                            color = MaterialTheme.colorScheme.primary,
                            fontWeight = FontWeight.Bold,
                            fontSize = 14.sp
                        )
                        Spacer(modifier = Modifier.width(8.dp))
                        Text(
                            text = feature,
                            style = MaterialTheme.typography.bodyMedium
                        )
                    }
                }

                Spacer(modifier = Modifier.height(16.dp))

                Button(
                    onClick = { details?.let { onSubscribe(it) } },
                    enabled = details != null && !isPurchasing,
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(12.dp),
                    colors = if (isHighlighted) {
                        ButtonDefaults.buttonColors()
                    } else {
                        ButtonDefaults.buttonColors(
                            containerColor = MaterialTheme.colorScheme.secondaryContainer,
                            contentColor = MaterialTheme.colorScheme.onSecondaryContainer
                        )
                    }
                ) {
                    if (isPurchasing) {
                        CircularProgressIndicator(
                            modifier = Modifier.size(16.dp),
                            color = Color.White,
                            strokeWidth = 2.dp
                        )
                        Spacer(modifier = Modifier.width(8.dp))
                    }
                    Text(
                        text = if (isPurchasing) "Processing..." else "Subscribe",
                        fontWeight = FontWeight.SemiBold
                    )
                }
            }
        }
    }

    private fun launchPurchase(details: ProductDetails) {
        val result = billingManager.launchPurchaseFlow(this, details)
        Log.d(TAG, "Launch billing flow result: ${result.responseCode}")
    }
}
