package br.com.esis.garcom.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** Garçom logado, com a empresa a que ele pertence. */
data class Waiter(val id: Long, val name: String, val companyName: String, val logoUrl: String?)

sealed interface LoginResult {
    data class Success(val waiter: Waiter) : LoginResult
    data class InvalidCredentials(val message: String) : LoginResult
    data class NotAWaiter(val message: String) : LoginResult
    data object NoConnection : LoginResult
}

@Serializable
data class LoginRequest(
    val email: String,
    val password: String,
    @SerialName("device_name") val deviceName: String,
)

@Serializable
data class LoginResponse(val token: String, val user: WaiterDto)

@Serializable
data class WaiterEnvelope(val data: WaiterDto)

@Serializable
data class WaiterDto(val id: Long, val name: String, val company: CompanyDto) {
    fun toWaiter(): Waiter = Waiter(id = id, name = name, companyName = company.name, logoUrl = company.logoUrl)
}

@Serializable
data class CompanyDto(val id: Long, val name: String, @SerialName("logo_url") val logoUrl: String? = null)

@Serializable
data class ApiError(val message: String? = null)
