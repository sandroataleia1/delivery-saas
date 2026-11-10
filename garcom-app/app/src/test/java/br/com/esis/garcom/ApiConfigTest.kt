package br.com.esis.garcom

import org.junit.Assert.assertEquals
import org.junit.Test

class ApiConfigTest {
    @Test
    fun base_url_aponta_para_a_api_v1_com_barra_final() {
        assertEquals("https://delivery.esis.com.br/api/v1/", ApiConfig.baseUrl())
    }
}
