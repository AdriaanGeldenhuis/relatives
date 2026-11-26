pluginManagement {
    repositories {
        google {
            content {
                includeGroupByRegex("com\\.android.*")
                includeGroupByRegex("com\\.google.*")
                includeGroupByRegex("androidx.*")
            }
        }
        mavenCentral()
        gradlePluginPortal()
    }
}

val mapboxDownloadsToken = providers.environmentVariable("MAPBOX_DOWNLOADS_TOKEN")
    .filter { it.isNotBlank() }
    .orElse(providers.gradleProperty("MAPBOX_DOWNLOADS_TOKEN").filter { it.isNotBlank() })

dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
        if (mapboxDownloadsToken.isPresent) {
            maven {
                url = uri("https://api.mapbox.com/downloads/v2/releases/maven")
                content {
                    includeGroupByRegex("com\\.mapbox(\\..+)?")
                }
                authentication {
                    create<BasicAuthentication>("basic")
                }
                credentials {
                    username = "mapbox"
                    password = mapboxDownloadsToken.get()
                }
            }
        }
    }
}

rootProject.name = "Relatives"
include(":app")
