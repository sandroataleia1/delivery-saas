package br.com.esis.garcom.data

import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path

interface ApiService {
    @POST("login")
    suspend fun login(@Body body: LoginRequest): LoginResponse

    @GET("me")
    suspend fun me(): WaiterEnvelope

    @POST("logout")
    suspend fun logout(): Response<Unit>

    @GET("tables")
    suspend fun tables(): TablesEnvelope

    @GET("menu")
    suspend fun menu(): MenuDto

    @GET("orders/{id}")
    suspend fun order(@Path("id") id: Long): OrderEnvelope

    @POST("tables/{id}/open")
    suspend fun openTable(@Path("id") tableId: Long, @Body body: OpenTableRequest): OrderEnvelope

    @POST("orders/{id}/items")
    suspend fun addItems(@Path("id") orderId: Long, @Body body: AddItemsRequest): OrderEnvelope

    @POST("orders/{id}/quote")
    suspend fun quote(@Path("id") orderId: Long, @Body body: ItemRequest): QuoteDto

    @POST("orders/{id}/bill")
    suspend fun requestBill(@Path("id") orderId: Long, @Body body: RequestBillRequest): OrderEnvelope

    @POST("orders/{id}/transfer")
    suspend fun transfer(@Path("id") orderId: Long, @Body body: TransferRequest): OrderEnvelope

    @POST("orders/{id}/deliver")
    suspend fun deliver(@Path("id") orderId: Long, @Body body: DeliverRequest): OrderEnvelope

    @POST("orders/{id}/prebill")
    suspend fun preBill(@Path("id") orderId: Long): Response<Unit>

    @POST("receipts/{id}/confirm")
    suspend fun confirmReceipt(@Path("id") receiptId: Long): OrderEnvelope

    @POST("receipts/{id}/reject")
    suspend fun rejectReceipt(@Path("id") receiptId: Long, @Body body: RejectReceiptRequest): OrderEnvelope

    @GET("notices")
    suspend fun notices(): NoticesEnvelope

    @POST("notices/read")
    suspend fun readNotices(@Body body: ReadNoticesRequest): UnreadDto

    @GET("app")
    suspend fun app(): AppInfoDto
}
