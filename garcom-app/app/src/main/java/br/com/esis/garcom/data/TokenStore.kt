package br.com.esis.garcom.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.first
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

interface TokenStore {
    suspend fun read(): String?
    suspend fun save(token: String)
    suspend fun clear()
}

/** Último garçom logado, para o app abrir no salão mesmo sem internet. */
interface WaiterCache {
    suspend fun readWaiter(): Waiter?
    suspend fun saveWaiter(waiter: Waiter)
}

private val Context.sessionDataStore by preferencesDataStore(name = "session")

/**
 * Sessão do aparelho em DataStore. O token fica cifrado com uma chave AES/GCM do Android Keystore,
 * que nunca sai do aparelho; se a chave sumir, o token não abre e o garçom entra de novo.
 */
class KeystoreSessionStore(context: Context) : TokenStore, WaiterCache {
    private val dataStore = context.applicationContext.sessionDataStore

    @Volatile
    private var cachedToken: String? = null

    @Volatile
    private var loaded = false

    override suspend fun read(): String? {
        if (!loaded) {
            cachedToken = runCatching { dataStore.data.first()[TOKEN]?.let(::decrypt) }.getOrNull()
            loaded = true
        }
        return cachedToken
    }

    override suspend fun save(token: String) {
        dataStore.edit { it[TOKEN] = encrypt(token) }
        cachedToken = token
        loaded = true
    }

    override suspend fun clear() {
        dataStore.edit { it.clear() }
        cachedToken = null
        loaded = true
    }

    override suspend fun readWaiter(): Waiter? {
        val raw = dataStore.data.first()[WAITER] ?: return null
        return runCatching { ApiClient.json.decodeFromString(WaiterDto.serializer(), raw).toWaiter() }.getOrNull()
    }

    override suspend fun saveWaiter(waiter: Waiter) {
        val dto = WaiterDto(waiter.id, waiter.name, CompanyDto(0, waiter.companyName, waiter.logoUrl), waiter.permissions.toList())
        dataStore.edit { it[WAITER] = ApiClient.json.encodeToString(WaiterDto.serializer(), dto) }
    }

    private fun key(): SecretKey {
        val keyStore = KeyStore.getInstance(KEYSTORE).apply { load(null) }
        (keyStore.getKey(ALIAS, null) as? SecretKey)?.let { return it }

        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE).apply {
            init(
                KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                    .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                    .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                    .build(),
            )
        }.generateKey()
    }

    /** Chave inválida (comum após atualização do Android em alguns aparelhos): apaga e cria outra, uma vez. */
    private fun encrypt(plain: String): String = try {
        seal(plain)
    } catch (error: Exception) {
        KeyStore.getInstance(KEYSTORE).apply { load(null) }.deleteEntry(ALIAS)
        seal(plain)
    }

    private fun seal(plain: String): String {
        val cipher = Cipher.getInstance(TRANSFORMATION).apply { init(Cipher.ENCRYPT_MODE, key()) }
        val sealed = cipher.doFinal(plain.toByteArray(Charsets.UTF_8))
        return encode(cipher.iv) + ":" + encode(sealed)
    }

    private fun decrypt(stored: String): String? = runCatching {
        val (iv, sealed) = stored.split(":", limit = 2)
        val cipher = Cipher.getInstance(TRANSFORMATION).apply {
            init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, decode(iv)))
        }
        String(cipher.doFinal(decode(sealed)), Charsets.UTF_8)
    }.getOrNull()

    private fun encode(bytes: ByteArray): String = Base64.encodeToString(bytes, Base64.NO_WRAP)

    private fun decode(text: String): ByteArray = Base64.decode(text, Base64.NO_WRAP)

    private companion object {
        const val KEYSTORE = "AndroidKeyStore"
        const val ALIAS = "garcom_session_token"
        const val TRANSFORMATION = "AES/GCM/NoPadding"
        val TOKEN = stringPreferencesKey("token")
        val WAITER = stringPreferencesKey("waiter")
    }
}
