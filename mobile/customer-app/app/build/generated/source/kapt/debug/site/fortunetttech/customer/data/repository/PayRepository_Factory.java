package site.fortunetttech.customer.data.repository;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.customer.data.network.ApiService;

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
public final class PayRepository_Factory implements Factory<PayRepository> {
  private final Provider<ApiService> apiProvider;

  public PayRepository_Factory(Provider<ApiService> apiProvider) {
    this.apiProvider = apiProvider;
  }

  @Override
  public PayRepository get() {
    return newInstance(apiProvider.get());
  }

  public static PayRepository_Factory create(Provider<ApiService> apiProvider) {
    return new PayRepository_Factory(apiProvider);
  }

  public static PayRepository newInstance(ApiService api) {
    return new PayRepository(api);
  }
}
