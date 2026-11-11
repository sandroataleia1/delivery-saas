package br.com.esis.garcom.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonPrimitive

data class MenuCategory(val id: Long, val name: String)

data class MenuOption(val id: Long, val name: String, val price: String, val paused: Boolean, val pricesBySize: Map<Long, String>)

data class PizzaSize(val id: Long, val name: String, val slices: Int, val maxFlavors: Int)

data class OptionGroup(val id: Long, val name: String, val min: Int, val max: Int?, val allowsQuantity: Boolean, val options: List<MenuOption>)

data class Pizza(val sizes: List<PizzaSize>, val flavors: List<MenuOption>)

/** O que dá para montar no produto. O preço final sempre vem da cotação do servidor. */
data class Composition(val pizza: Pizza?, val groups: List<OptionGroup>)

data class MenuProduct(
    val id: Long,
    val name: String,
    val categoryId: Long?,
    val price: String,
    val fromPrice: String,
    val composition: Composition?,
)

data class Menu(val categories: List<MenuCategory>, val products: List<MenuProduct>)

interface MenuRepository {
    suspend fun menu(): ApiResult<Menu>
}

class ApiMenuRepository(private val api: ApiService) : MenuRepository {
    override suspend fun menu(): ApiResult<Menu> = apiCall { api.menu().toMenu() }
}

@Serializable
data class MenuDto(val categories: List<CategoryDto> = emptyList(), val products: List<MenuProductDto> = emptyList()) {
    fun toMenu(): Menu = Menu(categories.map { MenuCategory(it.id, it.name) }, products.map { it.toProduct() })
}

@Serializable
data class CategoryDto(val id: Long, val name: String)

@Serializable
data class MenuProductDto(
    val id: Long,
    val name: String,
    @SerialName("category_id") val categoryId: Long? = null,
    val price: String,
    @SerialName("from_price") val fromPrice: String? = null,
    val composition: CompositionDto? = null,
) {
    fun toProduct(): MenuProduct = MenuProduct(id, name, categoryId, price, fromPrice ?: price, composition?.toComposition())
}

@Serializable
data class CompositionDto(val pizza: PizzaDto? = null, val groups: List<GroupDto> = emptyList()) {
    fun toComposition(): Composition = Composition(pizza?.toPizza(), groups.map { it.toGroup() })
}

@Serializable
data class PizzaDto(val sizes: List<SizeDto> = emptyList(), val flavors: List<OptionDto> = emptyList()) {
    fun toPizza(): Pizza = Pizza(sizes.map { PizzaSize(it.id, it.name, it.slices, it.maxFlavors) }, flavors.map { it.toOption() })
}

@Serializable
data class SizeDto(val id: Long, val name: String, val slices: Int = 0, @SerialName("max_flavors") val maxFlavors: Int = 1)

@Serializable
data class GroupDto(
    val id: Long,
    val name: String,
    val min: Int = 0,
    val max: Int? = null,
    @SerialName("allows_quantity") val allowsQuantity: Boolean = false,
    val options: List<OptionDto> = emptyList(),
) {
    fun toGroup(): OptionGroup = OptionGroup(id, name, min, max, allowsQuantity, options.map { it.toOption() })
}

@Serializable
data class OptionDto(
    val id: Long,
    val name: String,
    val price: String? = null,
    val paused: Boolean = false,
    /** O PHP manda um objeto {tamanho: preço}, ou [] quando não há preço por tamanho. */
    val prices: JsonElement? = null,
) {
    fun toOption(): MenuOption = MenuOption(id, name, price ?: "0.00", paused, pricesBySize())

    private fun pricesBySize(): Map<Long, String> = (prices as? JsonObject)
        ?.mapNotNull { (size, price) -> size.toLongOrNull()?.let { id -> price.jsonPrimitive.contentOrNull?.let { id to it } } }
        ?.toMap()
        .orEmpty()
}
