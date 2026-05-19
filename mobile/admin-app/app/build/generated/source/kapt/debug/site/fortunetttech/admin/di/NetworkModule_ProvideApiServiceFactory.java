package site.fortunetttech.admin.di;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.Preconditions;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.admin.data.network.ApiService;
import site.fortunetttech.admin.data.preferences.TokenPreferences;

@ScopeMetadata("javax.inject.Singleton")
@QualifierMetadata
@DaggerGenerated
@Generated(
    value = "dagger.internal.codegen.ComponentProcessor",
    comments = "https://dagger.dev"
)
@SuppressWarnings({
    "unchecked",
    "rawtypes",
    "KotlinInternal",
    "KotlinInternalInJava",
    "cast"
})
public final class NetworkModule_ProvideApiServiceFactory implements Factory<ApiService> {
  private final Provider<TokenPreferences> prefsProvider;

  public NetworkModule_ProvideApiServiceFactory(Provider<TokenPreferences> prefsProvider) {
    this.prefsProvider = prefsProvider;
  }

  @Override
  public ApiService get() {
    return provideApiService(prefsProvider.get());
  }

  public static NetworkModule_ProvideApiServiceFactory create(
      Provider<TokenPreferences> prefsProvider) {
    return new NetworkModule_ProvideApiServiceFactory(prefsProvider);
  }

  public static ApiService provideApiService(TokenPreferences prefs) {
    return Preconditions.checkNotNullFromProvides(NetworkModule.INSTANCE.provideApiService(prefs));
  }
}
