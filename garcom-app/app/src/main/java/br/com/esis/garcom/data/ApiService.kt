package br.com.esis.garcom.data

import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST

interface ApiService {
    @POST("login")
    suspend fun login(@Body body: LoginRequest): LoginResponse

    @GET("me")
    suspend fun me(): WaiterEnvelope

    @POST("logout")
    suspend fun logout(): Response<Unit>

    @GET("tables")
    suspend fun tables(): TablesEnvelope
}
