package br.com.esis.garcom

/** Endereço da API do sistema; sempre termina em barra, como o Retrofit exige. */
object ApiConfig {
    fun baseUrl(): String = BuildConfig.API_BASE_URL.trimEnd('/') + "/"
}
