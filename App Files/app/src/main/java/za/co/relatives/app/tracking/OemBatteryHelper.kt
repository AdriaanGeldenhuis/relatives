package za.co.relatives.app.tracking

import android.app.Activity
import android.app.AlertDialog
import android.content.ComponentName
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import android.util.Log
import za.co.relatives.app.utils.PreferencesManager

/**
 * OemBatteryHelper — guidance for OEM battery killers.
 *
 * The standard REQUEST_IGNORE_BATTERY_OPTIMIZATIONS exemption is NOT enough
 * on Huawei/Honor (EMUI/MagicOS), Xiaomi (MIUI), Oppo, Vivo, Transsion and
 * friends: their proprietary "app launch" / autostart managers kill the
 * tracking foreground service within minutes-to-hours regardless of the
 * Android-level exemption, and block the app from restarting itself. The only
 * fix is the user flipping the app to manual management in the OEM's own
 * settings screen — which this helper deep-links to, with per-OEM copy.
 *
 * Component lists follow the dontkillmyapp.com catalogue; every launch is
 * wrapped in try/catch because OEMs rename these activities between releases,
 * and the fallback is the app's own details page.
 */
object OemBatteryHelper {

    private const val TAG = "OemBatteryHelper"

    private data class OemGuide(
        val label: String,
        /** What the user must do once the settings screen opens. */
        val instructions: String,
        /** Candidate settings screens, most specific first. */
        val components: List<ComponentName>,
    )

    private val HUAWEI = OemGuide(
        label = "Huawei",
        instructions = "Find Relatives, open \"App launch\" and switch OFF " +
            "\"Manage automatically\", then switch ON all three options: " +
            "Auto-launch, Secondary launch and Run in background.",
        components = listOf(
            ComponentName(
                "com.huawei.systemmanager",
                "com.huawei.systemmanager.startupmgr.ui.StartupNormalAppListActivity",
            ),
            ComponentName(
                "com.huawei.systemmanager",
                "com.huawei.systemmanager.appcontrol.activity.StartupAppControlActivity",
            ),
            ComponentName(
                "com.huawei.systemmanager",
                "com.huawei.systemmanager.optimize.process.ProtectActivity",
            ),
        ),
    )

    private val HONOR = OemGuide(
        label = "Honor",
        instructions = "Find Relatives, open \"App launch\" and switch OFF " +
            "\"Manage automatically\", then switch ON all three options: " +
            "Auto-launch, Secondary launch and Run in background.",
        components = listOf(
            // Post-split Honor (MagicOS) ships its own system manager…
            ComponentName(
                "com.hihonor.systemmanager",
                "com.hihonor.systemmanager.startupmgr.ui.StartupNormalAppListActivity",
            ),
            ComponentName(
                "com.hihonor.systemmanager",
                "com.hihonor.systemmanager.appcontrol.activity.StartupAppControlActivity",
            ),
            // …older Honor devices still run Huawei's.
            ComponentName(
                "com.huawei.systemmanager",
                "com.huawei.systemmanager.startupmgr.ui.StartupNormalAppListActivity",
            ),
            ComponentName(
                "com.huawei.systemmanager",
                "com.huawei.systemmanager.appcontrol.activity.StartupAppControlActivity",
            ),
        ),
    )

    private val XIAOMI = OemGuide(
        label = "Xiaomi",
        instructions = "Find Relatives and enable \"Autostart\". Then in " +
            "Battery settings set Relatives to \"No restrictions\".",
        components = listOf(
            ComponentName(
                "com.miui.securitycenter",
                "com.miui.permcenter.autostart.AutoStartManagementActivity",
            ),
        ),
    )

    private val OPPO = OemGuide(
        label = "Oppo",
        instructions = "Find Relatives and allow \"Auto-launch\" and " +
            "background running.",
        components = listOf(
            ComponentName(
                "com.coloros.safecenter",
                "com.coloros.safecenter.permission.startup.StartupAppListActivity",
            ),
            ComponentName(
                "com.coloros.safecenter",
                "com.coloros.safecenter.startupapp.StartupAppListActivity",
            ),
            ComponentName(
                "com.oppo.safe",
                "com.oppo.safe.permission.startup.StartupAppListActivity",
            ),
        ),
    )

    private val VIVO = OemGuide(
        label = "Vivo",
        instructions = "Find Relatives and allow auto-start / background " +
            "running (high background power consumption).",
        components = listOf(
            ComponentName(
                "com.vivo.permissionmanager",
                "com.vivo.permissionmanager.activity.BgStartUpManagerActivity",
            ),
            ComponentName(
                "com.iqoo.secure",
                "com.iqoo.secure.ui.phoneoptimize.BgStartUpManager",
            ),
        ),
    )

    private val ONEPLUS = OemGuide(
        label = "OnePlus",
        instructions = "Find Relatives and allow auto-launch. Also set " +
            "Battery optimisation for Relatives to \"Don't optimise\".",
        components = listOf(
            ComponentName(
                "com.oneplus.security",
                "com.oneplus.security.chainlaunch.view.ChainLaunchAppListActivity",
            ),
        ),
    )

    private val SAMSUNG = OemGuide(
        label = "Samsung",
        instructions = "Open Battery, then make sure Relatives is NOT in the " +
            "\"Sleeping apps\" or \"Deep sleeping apps\" lists, and turn off " +
            "\"Put unused apps to sleep\" if tracking keeps stopping.",
        components = listOf(
            ComponentName(
                "com.samsung.android.lool",
                "com.samsung.android.sm.battery.ui.BatteryActivity",
            ),
            ComponentName(
                "com.samsung.android.lool",
                "com.samsung.android.sm.ui.battery.BatteryActivity",
            ),
        ),
    )

    private val TRANSSION = OemGuide(
        label = "your phone",
        instructions = "Find Relatives and allow auto-start and background " +
            "running in the phone manager.",
        components = listOf(
            ComponentName(
                "com.transsion.phonemanager",
                "com.itel.autobootmanager.activity.AutoBootMgrActivity",
            ),
        ),
    )

    // Meizu's security screen is launched by ACTION, not a component class —
    // handled specially in openOemSettings; no ComponentName candidates.
    private val MEIZU = OemGuide(
        label = "Meizu",
        instructions = "Find Relatives and allow background running.",
        components = emptyList(),
    )

    /** Manufacturer/brand (lowercase) → guide. */
    private fun guideForDevice(): OemGuide? {
        val ids = setOf(
            Build.MANUFACTURER.lowercase(),
            Build.BRAND.lowercase(),
        )
        return when {
            "huawei" in ids -> HUAWEI
            "honor" in ids || "hihonor" in ids -> HONOR
            "xiaomi" in ids || "redmi" in ids || "poco" in ids -> XIAOMI
            "oppo" in ids || "realme" in ids -> OPPO
            "vivo" in ids || "iqoo" in ids -> VIVO
            "oneplus" in ids -> ONEPLUS
            "samsung" in ids -> SAMSUNG
            "infinix" in ids || "tecno" in ids || "itel" in ids -> TRANSSION
            "meizu" in ids -> MEIZU
            else -> null
        }
    }

    /** True when this device's OEM is known to kill background tracking. */
    fun isAggressiveOem(): Boolean = guideForDevice() != null

    /**
     * Offer the OEM-specific "keep tracking alive" walkthrough. Shown once
     * (per [PreferencesManager.oemGuidanceShown]) unless [force] — callers
     * re-force it from an explicit "tracking keeps stopping" help action.
     */
    fun maybeShowGuidance(activity: Activity, prefs: PreferencesManager, force: Boolean = false) {
        val guide = guideForDevice() ?: return
        if (!force && prefs.oemGuidanceShown) return
        if (activity.isFinishing || activity.isDestroyed) return
        prefs.oemGuidanceShown = true

        try {
            AlertDialog.Builder(activity, android.R.style.Theme_DeviceDefault_Dialog_Alert)
                .setTitle("One more step on ${guide.label} phones")
                .setMessage(
                    "${guide.label} phones stop location sharing in the " +
                        "background unless you allow Relatives to keep " +
                        "running.\n\n${guide.instructions}",
                )
                .setPositiveButton("Open settings") { _, _ ->
                    openOemSettings(activity, guide)
                }
                .setNegativeButton("Not now", null)
                .setCancelable(true)
                .show()
        } catch (e: Exception) {
            Log.w(TAG, "OEM guidance dialog failed", e)
        }
    }

    /**
     * Launch the OEM's autostart/app-launch manager, falling back through the
     * candidate list and finally to the app's own settings page (which on
     * most OEM skins links to the same battery controls).
     */
    private fun openOemSettings(activity: Activity, guide: OemGuide) {
        // Meizu exposes its app-security screen via an ACTION intent with the
        // package name as an extra, not a launchable activity class.
        if (guide === MEIZU) {
            try {
                activity.startActivity(
                    Intent("com.meizu.safe.security.SHOW_APPSEC").apply {
                        addCategory(Intent.CATEGORY_DEFAULT)
                        putExtra("packageName", activity.packageName)
                        addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    },
                )
                return
            } catch (_: Exception) {
                // Fall through to the app-details page below.
            }
        }
        for (component in guide.components) {
            try {
                activity.startActivity(
                    Intent().apply {
                        setComponent(component)
                        addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    },
                )
                return
            } catch (_: Exception) {
                // Component missing/renamed on this OS version — try the next.
            }
        }
        try {
            activity.startActivity(
                Intent(
                    Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                    Uri.fromParts("package", activity.packageName, null),
                ),
            )
        } catch (e: Exception) {
            Log.w(TAG, "Could not open any OEM settings screen", e)
        }
    }
}
