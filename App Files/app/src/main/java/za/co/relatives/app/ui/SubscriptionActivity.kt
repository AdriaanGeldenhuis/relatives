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
import za.co.relatives.app.billing.BillingManager
import za.co.relatives.app.network.ApiClient
import za.co.relatives.app.MainActivity
import za.co.relatives.app.utils.PreferencesManager

class SubscriptionActivity : ComponentActivity() {

    private lateinit var billingManager: BillingManager

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        
        billingManager = BillingManager(this) { planCode, token ->
            verifyPurchaseOnBackend(planCode, token)
        }
        billingManager.startConnection()

        setContent {
            SubscriptionScreen(billingManager)
        }
    }

    private fun verifyPurchaseOnBackend(planCode: String, token: String) {
        val familyId = PreferencesManager.getDeviceUuid() // In real app, this should be family_id from session
        
        ApiClient.verifyPurchase(familyId, planCode, token) { success ->
            runOnUiThread {
                if (success) {
                    Toast.makeText(this, "Subscription Active!", Toast.LENGTH_LONG).show()
                    startActivity(Intent(this, MainActivity::class.java))
                    finish()
                } else {
                    Toast.makeText(this, "Verification failed", Toast.LENGTH_SHORT).show()
                }
            }
        }
    }
}

@Composable
fun SubscriptionScreen(billingManager: BillingManager) {
    val products by billingManager.productDetails.collectAsState()
    val context = LocalContext.current

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(16.dp)
            .verticalScroll(rememberScrollState()),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Spacer(modifier = Modifier.height(48.dp))
        Text("Your trial has ended", style = MaterialTheme.typography.headlineMedium)
        Text("Choose a plan to continue tracking.", style = MaterialTheme.typography.bodyMedium)
        Spacer(modifier = Modifier.height(24.dp))

        if (products.isEmpty()) {
            CircularProgressIndicator()
            Spacer(modifier = Modifier.height(16.dp))
            Text("Loading plans from Google Play...")
        } else {
            // Sort products to show Starter -> Family -> Big
            products.sortedBy { it.productId }.forEach { product ->
                PlanCard(product) {
                    billingManager.launchPurchaseFlow(context as Activity, product)
                }
                Spacer(modifier = Modifier.height(12.dp))
            }
        }
        
        Spacer(modifier = Modifier.height(24.dp))
        Text("Auto-renews monthly. Cancel anytime.", style = MaterialTheme.typography.labelSmall)
    }
}

@Composable
fun PlanCard(product: com.android.billingclient.api.ProductDetails, onClick: () -> Unit) {
    val offer = product.subscriptionOfferDetails?.firstOrNull()
    val price = offer?.pricingPhases?.pricingPhaseList?.firstOrNull()?.formattedPrice ?: "..."

    Card(
        onClick = onClick,
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer)
    ) {
        Column(modifier = Modifier.padding(16.dp)) {
            Text(product.name, style = MaterialTheme.typography.titleMedium)
            Text(product.description, style = MaterialTheme.typography.bodySmall)
            Spacer(modifier = Modifier.height(8.dp))
            Text(price, style = MaterialTheme.typography.titleLarge)
        }
    }
}
