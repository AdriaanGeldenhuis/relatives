package za.co.relatives.app.ui

import android.app.Activity
import android.content.Intent
import android.os.Bundle
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import com.android.billingclient.api.ProductDetails
import za.co.relatives.app.billing.BillingManager
import za.co.relatives.app.network.ApiClient
import za.co.relatives.app.MainActivity
import za.co.relatives.app.ui.theme.RelativesTheme
import za.co.relatives.app.utils.PreferencesManager
import za.co.relatives.app.findActivity

class SubscriptionActivity : ComponentActivity() {

    private lateinit var billingManager: BillingManager

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        
        billingManager = BillingManager(this)
        
        billingManager.startConnection {
            // Connection is ready, UI will update automatically
        }
        
        billingManager.onPurchaseCompleted = { productId, purchaseToken ->
            handlePurchase(productId, purchaseToken)
        }

        setContent {
            RelativesTheme {
                SubscriptionScreen(billingManager = billingManager)
            }
        }
    }
    
    override fun onDestroy() {
        super.onDestroy()
        billingManager.endConnection()
    }

    private fun handlePurchase(productId: String, purchaseToken: String) {
        val familyId = PreferencesManager.getDeviceUuid()
        val planCode = mapProductToPlanCode(productId)

        if (familyId.isBlank() || planCode == "unknown") {
            showError("Could not verify purchase. Invalid details.")
            return
        }
        
        ApiClient.startGooglePlaySubscription(familyId, planCode, productId, purchaseToken) { success ->
            runOnUiThread {
                if (success) {
                    billingManager.acknowledgePurchase(purchaseToken) { ackSuccess ->
                       if (ackSuccess) {
                           Toast.makeText(this, "Subscription activated!", Toast.LENGTH_LONG).show()
                           // Relaunch MainActivity to refresh its state
                           val intent = Intent(this, MainActivity::class.java).apply {
                               flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
                           }
                           startActivity(intent)
                           finish()
                       } else {
                           showError("Purchase successful but failed to acknowledge. Please contact support.")
                       }
                    }
                } else {
                    showError("Backend verification failed. Your purchase will be refunded if payment was processed.")
                }
            }
        }
    }
    
    private fun showError(message: String) {
        Toast.makeText(this, message, Toast.LENGTH_LONG).show()
    }

    private fun mapProductToPlanCode(productId: String): String = when (productId) {
        "relatives.small.monthly" -> "small"
        "relatives.big.monthly" -> "big"
        else -> "unknown"
    }
}

@Composable
fun SubscriptionScreen(billingManager: BillingManager) {
    val products by billingManager.subscriptions.collectAsState()
    val context = LocalContext.current
    val activity = context.findActivity() ?: return

    Surface(modifier = Modifier.fillMaxSize()) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(16.dp)
                .verticalScroll(rememberScrollState()),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center
        ) {
            Text("Choose Your Plan", style = MaterialTheme.typography.headlineLarge)
            Spacer(modifier = Modifier.height(16.dp))
            Text("Unlock all features to stay connected.", style = MaterialTheme.typography.bodyMedium)
            Spacer(modifier = Modifier.height(32.dp))

            if (products.isEmpty()) {
                CircularProgressIndicator()
                Spacer(modifier = Modifier.height(16.dp))
                Text("Loading plans...", style = MaterialTheme.typography.bodySmall)
            } else {
                products.sortedBy { it.productId }.forEach { product ->
                    PlanCard(product = product) {
                        billingManager.launchPurchase(activity, product)
                    }
                    Spacer(modifier = Modifier.height(16.dp))
                }
            }
        }
    }
}

@Composable
fun PlanCard(product: ProductDetails, onClick: () -> Unit) {
    val offer = product.subscriptionOfferDetails?.firstOrNull()
    val price = offer?.pricingPhases?.pricingPhaseList?.firstOrNull()?.formattedPrice ?: "..."

    Card(
        onClick = onClick,
        modifier = Modifier.fillMaxWidth(),
        elevation = CardDefaults.cardElevation(defaultElevation = 4.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceVariant)
    ) {
        Row(
            modifier = Modifier
                .padding(16.dp)
                .fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.SpaceBetween
        ) {
            Column {
                Text(product.name, style = MaterialTheme.typography.titleLarge)
                Text(product.description, style = MaterialTheme.typography.bodyMedium)
            }
            Text(price, style = MaterialTheme.typography.titleMedium)
        }
    }
}
