package site.fortunetttech.customer.di

import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import site.fortunetttech.customer.data.network.ApiService
import site.fortunetttech.customer.data.network.buildApiService
import site.fortunetttech.customer.data.preferences.TokenPreferences
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
object NetworkModule {

    @Provides
    @Singleton
    fun provideApiService(prefs: TokenPreferences): ApiService = buildApiService(prefs)
}
